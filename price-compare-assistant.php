<?php
/**
 * Plugin Name: دستیار مقایسه قیمت
 * Plugin URI: https://github.com/sahandse/price-compare-assistant
 * Description: مقایسه قیمت و اطلاعات محصولات ووکامرس با منابع خارجی و پیشنهاد بروزرسانی قابل تایید توسط مدیر.
 * Version: 1.3.0
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: price-compare-assistant
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class PCA_Plugin {
    const VERSION = '1.3.0';
    const OPTION  = 'pca_settings';

    public function __construct() {
        add_action('before_woocommerce_init', [$this, 'declare_hpos']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function declare_hpos() {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        add_action('wp_ajax_pca_search_product', [$this, 'ajax_search_product']);
        add_action('wp_ajax_pca_select_match', [$this, 'ajax_select_match']);
        add_action('wp_ajax_pca_refresh_price', [$this, 'ajax_refresh_price']);
        add_action('wp_ajax_pca_apply_price', [$this, 'ajax_apply_price']);
    }

    public function woocommerce_notice() {
        echo '<div class="notice notice-error"><p>دستیار مقایسه قیمت برای اجرا به WooCommerce نیاز دارد.</p></div>';
    }

    public function defaults() {
        return [
            'enabled' => 'yes',
            'search_by' => 'name_sku',
            'auto_suggest' => 'yes',
            'compare_price' => 'yes',
            'compare_content' => 'yes',
            'accent' => '#111827',
            'sources' => 'digikala,torob,basalam',
        ];
    }

    public function settings() {
        return wp_parse_args((array)get_option(self::OPTION, []), $this->defaults());
    }

    public function register_settings() {
        register_setting('pca_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $d = $this->defaults();
        return [
            'enabled' => !empty($in['enabled']) ? 'yes' : 'no',
            'search_by' => in_array($in['search_by'] ?? '', ['name','sku','name_sku'], true) ? $in['search_by'] : $d['search_by'],
            'auto_suggest' => !empty($in['auto_suggest']) ? 'yes' : 'no',
            'compare_price' => !empty($in['compare_price']) ? 'yes' : 'no',
            'compare_content' => !empty($in['compare_content']) ? 'yes' : 'no',
            'accent' => sanitize_hex_color($in['accent'] ?? '') ?: $d['accent'],
            'sources' => sanitize_text_field($in['sources'] ?? $d['sources']),
        ];
    }

    public function admin_menu() {
        if (function_exists('s_store_register_submenu')) {
            s_store_register_submenu(
                'price-compare-assistant',
                'دستیار مقایسه قیمت',
                [$this, 'products_page'],
                'manage_woocommerce',
                'دستیار مقایسه قیمت'
            );
            add_submenu_page(
                's-store',
                'محصولات و قیمت‌ها',
                '↳ محصولات و قیمت‌ها',
                'manage_woocommerce',
                'price-compare-assistant-products',
                [$this, 'products_page']
            );
            add_submenu_page(
                's-store',
                'تنظیمات دستیار مقایسه قیمت',
                '↳ تنظیمات',
                'manage_woocommerce',
                'price-compare-assistant-settings',
                [$this, 'settings_page']
            );
            return;
        }

        add_submenu_page(
            'woocommerce',
            'دستیار مقایسه قیمت',
            'مقایسه قیمت',
            'manage_woocommerce',
            'price-compare-assistant',
            [$this, 'products_page']
        );
        add_submenu_page(
            'woocommerce',
            'محصولات و قیمت‌ها',
            '↳ محصولات و قیمت‌ها',
            'manage_woocommerce',
            'price-compare-assistant-products',
            [$this, 'products_page']
        );
        add_submenu_page(
            'woocommerce',
            'تنظیمات دستیار مقایسه قیمت',
            '↳ تنظیمات',
            'manage_woocommerce',
            'price-compare-assistant-settings',
            [$this, 'settings_page']
        );
    }

    public function admin_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (0 !== strpos($page, 'price-compare-assistant') && false === strpos($hook, 'price-compare-assistant')) return;

        wp_enqueue_style('pca-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('pca-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], self::VERSION, true);
        wp_localize_script('pca-admin', 'PCAAdmin', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pca_admin'),
            'currency' => get_woocommerce_currency(),
            'labels' => [
                'searching' => 'در حال جستجو…',
                'loading' => 'در حال دریافت قیمت…',
                'notFound' => 'نتیجه قابل‌اعتمادی پیدا نشد.',
                'saved' => 'قیمت ذخیره شد.',
                'error' => 'خطایی رخ داد.',
            ],
        ]);
    }

    private function source_config($source) {
        $map = [
            'digikala' => [
                'label' => 'دیجی‌کالا',
                'host' => 'digikala.com',
                'search' => 'https://www.digikala.com/search/?q=%s',
                'patterns' => ['/product/'],
            ],
            'torob' => [
                'label' => 'ترب',
                'host' => 'torob.com',
                'search' => 'https://torob.com/search/?query=%s',
                'patterns' => ['/p/', '/product/'],
            ],
            'basalam' => [
                'label' => 'باسلام',
                'host' => 'basalam.com',
                'search' => 'https://basalam.com/search?q=%s',
                'patterns' => ['/p/', '/product/'],
            ],
        ];
        return $map[$source] ?? null;
    }

    private function product_query_text($product) {
        return trim((string) $product->get_name());
    }

    private function normalize_title($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtr($value, [
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه',
            '‌' => ' ', '-' => ' ', '_' => ' ', '/' => ' ', '\\' => ' ',
        ]);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));

        $stop = ['خرید','قیمت','فروش','اصل','اورجینال','جدید','مدل','محصول','کالا','آنلاین','تومان','ریال'];
        $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter($tokens, function($token) use ($stop) {
            return mb_strlen($token, 'UTF-8') > 1 && !in_array($token, $stop, true);
        }));
        return implode(' ', $tokens);
    }

    private function title_similarity($needle, $candidate) {
        $a = $this->normalize_title($needle);
        $b = $this->normalize_title($candidate);
        if (!$a || !$b) return 0;

        if ($a === $b) return 100;

        $aTokens = array_values(array_unique(preg_split('/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY)));
        $bTokens = array_values(array_unique(preg_split('/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY)));

        $intersection = count(array_intersect($aTokens, $bTokens));
        $union = count(array_unique(array_merge($aTokens, $bTokens)));
        $tokenScore = $union ? ($intersection / $union) * 100 : 0;

        $containScore = 0;
        if (false !== mb_strpos($b, $a, 0, 'UTF-8') || false !== mb_strpos($a, $b, 0, 'UTF-8')) {
            $containScore = 92;
        }

        $aAscii = preg_replace('/\s+/u', '', $a);
        $bAscii = preg_replace('/\s+/u', '', $b);
        $maxLen = max(mb_strlen($aAscii, 'UTF-8'), mb_strlen($bAscii, 'UTF-8'));
        $levScore = 0;
        if ($maxLen && function_exists('levenshtein') && preg_match('/^[\x00-\x7F]+$/', $aAscii . $bAscii)) {
            $distance = levenshtein($aAscii, $bAscii);
            $levScore = max(0, (1 - ($distance / max(1, $maxLen))) * 100);
        }

        return (int) round(max($containScore, ($tokenScore * 0.82) + ($levScore * 0.18)));
    }

    private function allowed_source_url($url, $source) {
        $cfg = $this->source_config($source);
        if (!$cfg) return false;
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!$host) return false;
        $allowed = $cfg['host'];
        return $host === $allowed || substr($host, -strlen('.'.$allowed)) === '.'.$allowed;
    }

    private function absolute_url($href, $base) {
        if (!$href) return '';
        if (0 === strpos($href, '//')) return 'https:' . $href;
        if (preg_match('#^https?://#i', $href)) return $href;
        $scheme = wp_parse_url($base, PHP_URL_SCHEME) ?: 'https';
        $host = wp_parse_url($base, PHP_URL_HOST);
        if (!$host) return '';
        if ('/' !== substr($href, 0, 1)) $href = '/' . $href;
        return $scheme . '://' . $host . $href;
    }

    private function remote_html($url) {
        $response = wp_remote_get($url, [
            'timeout' => 14,
            'redirection' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; PCA/' . self::VERSION . '; ' . home_url('/') . ')',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.5',
            ],
        ]);
        if (is_wp_error($response)) return $response;
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return new WP_Error('pca_http', 'پاسخ نامعتبر از منبع: HTTP ' . $code);
        }
        return (string) wp_remote_retrieve_body($response);
    }

    private function search_source($source, $query) {
        $cfg = $this->source_config($source);
        if (!$cfg) return new WP_Error('pca_source', 'منبع نامعتبر است.');

        $search_url = sprintf($cfg['search'], rawurlencode($query));
        $html = $this->remote_html($search_url);
        if (is_wp_error($html)) return $html;

        $results = [];
        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();

            foreach ($dom->getElementsByTagName('a') as $a) {
                $href = $this->absolute_url(trim((string)$a->getAttribute('href')), $search_url);
                if (!$href || !$this->allowed_source_url($href, $source)) continue;

                $path = (string) wp_parse_url($href, PHP_URL_PATH);
                $matched = false;
                foreach ($cfg['patterns'] as $pattern) {
                    if (false !== strpos($path, $pattern)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) continue;

                $title = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($a->textContent)));
                if (mb_strlen($title, 'UTF-8') < 4) continue;

                $key = untrailingslashit($href);
                if (isset($results[$key])) continue;

                $results[$key] = [
                    'title' => mb_substr($title, 0, 180, 'UTF-8'),
                    'url' => $key,
                    'score' => $this->title_similarity($query, $title),
                ];

                if (count($results) >= 18) break;
            }
        }

        $results = array_values($results);
        usort($results, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        $results = array_slice($results, 0, 6);

        return [
            'source' => $source,
            'label' => $cfg['label'],
            'search_url' => $search_url,
            'results' => $results,
        ];
    }

    private function find_price_in_json($data, &$currency = '') {
        if (!is_array($data)) return null;

        if (isset($data['priceCurrency']) && is_string($data['priceCurrency'])) {
            $currency = strtoupper(sanitize_text_field($data['priceCurrency']));
        }

        foreach (['price', 'lowPrice'] as $key) {
            if (isset($data[$key])) {
                $raw = is_scalar($data[$key]) ? (string)$data[$key] : '';
                $number = (float) str_replace([',', ' '], '', $raw);
                if ($number > 0) return $number;
            }
        }

        if (isset($data['offers'])) {
            $found = $this->find_price_in_json($data['offers'], $currency);
            if ($found) return $found;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->find_price_in_json($value, $currency);
                if ($found) return $found;
            }
        }
        return null;
    }

    private function extract_price($url, $source) {
        if (!$this->allowed_source_url($url, $source)) {
            return new WP_Error('pca_url', 'آدرس محصول برای این منبع معتبر نیست.');
        }

        $html = $this->remote_html($url);
        if (is_wp_error($html)) return $html;

        $price = null;
        $currency = '';

        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (!is_array($data)) continue;
                $price = $this->find_price_in_json($data, $currency);
                if ($price) break;
            }
        }

        if (!$price && preg_match('#<meta[^>]+(?:property|itemprop)=["\'](?:product:price:amount|price)["\'][^>]+content=["\']([0-9.,]+)["\']#i', $html, $m)) {
            $price = (float) str_replace(',', '', $m[1]);
        }

        if (!$price) {
            return new WP_Error('pca_price_missing', 'قیمت قابل‌اعتماد از صفحه محصول استخراج نشد.');
        }

        $store_currency = strtoupper(get_woocommerce_currency());
        if ('IRR' === $currency && in_array($store_currency, ['IRT','TMN','TOMAN'], true)) $price = $price / 10;
        if (in_array($currency, ['IRT','TMN','TOMAN'], true) && 'IRR' === $store_currency) $price = $price * 10;

        return [
            'price' => (float)$price,
            'currency' => $store_currency,
            'checked_at' => current_time('timestamp'),
        ];
    }

    private function require_ajax_access() {
        check_ajax_referer('pca_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        }
    }

    public function ajax_search_product() {
        $this->require_ajax_access();

        $product_id = absint($_POST['product_id'] ?? 0);
        $source = sanitize_key($_POST['source'] ?? '');
        $product = wc_get_product($product_id);
        if (!$product || !$this->source_config($source)) {
            wp_send_json_error(['message' => 'محصول یا منبع معتبر نیست.']);
        }

        $query = $this->product_query_text($product);
        $result = $this->search_source($source, $query);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $best = !empty($result['results'][0]) ? $result['results'][0] : null;
        if ($best && (int)($best['score'] ?? 0) >= 34) {
            update_post_meta($product_id, '_pca_match_' . $source, [
                'url' => $best['url'],
                'title' => $best['title'],
                'score' => (int)$best['score'],
            ]);

            $price = $this->extract_price($best['url'], $source);
            if (!is_wp_error($price)) {
                update_post_meta($product_id, '_pca_price_' . $source, $price);
                $result['auto_match'] = [
                    'title' => $best['title'],
                    'url' => $best['url'],
                    'score' => (int)$best['score'],
                    'price' => $price['price'],
                    'formatted' => wp_strip_all_tags(wc_price($price['price'])),
                    'checked_at' => wp_date('Y/m/d H:i', $price['checked_at']),
                ];
            } else {
                $result['auto_match'] = [
                    'title' => $best['title'],
                    'url' => $best['url'],
                    'score' => (int)$best['score'],
                    'price' => null,
                    'message' => $price->get_error_message(),
                ];
            }
        }

        wp_send_json_success($result);
    }

    public function ajax_select_match() {
        $this->require_ajax_access();

        $product_id = absint($_POST['product_id'] ?? 0);
        $source = sanitize_key($_POST['source'] ?? '');
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $product = wc_get_product($product_id);

        if (!$product || !$this->source_config($source) || !$this->allowed_source_url($url, $source)) {
            wp_send_json_error(['message' => 'انتخاب معتبر نیست.']);
        }

        update_post_meta($product_id, '_pca_match_' . $source, [
            'url' => $url,
            'title' => $title,
        ]);

        $price = $this->extract_price($url, $source);
        if (is_wp_error($price)) {
            wp_send_json_success([
                'matched' => true,
                'price' => null,
                'message' => $price->get_error_message(),
            ]);
        }

        update_post_meta($product_id, '_pca_price_' . $source, $price);
        wp_send_json_success([
            'matched' => true,
            'price' => $price['price'],
            'formatted' => wp_strip_all_tags(wc_price($price['price'])),
            'checked_at' => wp_date('Y/m/d H:i', $price['checked_at']),
        ]);
    }

    public function ajax_refresh_price() {
        $this->require_ajax_access();

        $product_id = absint($_POST['product_id'] ?? 0);
        $source = sanitize_key($_POST['source'] ?? '');
        $match = (array) get_post_meta($product_id, '_pca_match_' . $source, true);
        if (empty($match['url'])) {
            wp_send_json_error(['message' => 'ابتدا محصول متناظر را انتخاب کنید.']);
        }

        $price = $this->extract_price($match['url'], $source);
        if (is_wp_error($price)) {
            wp_send_json_error(['message' => $price->get_error_message()]);
        }

        update_post_meta($product_id, '_pca_price_' . $source, $price);
        wp_send_json_success([
            'price' => $price['price'],
            'formatted' => wp_strip_all_tags(wc_price($price['price'])),
            'checked_at' => wp_date('Y/m/d H:i', $price['checked_at']),
        ]);
    }

    public function ajax_apply_price() {
        $this->require_ajax_access();

        $product_id = absint($_POST['product_id'] ?? 0);
        $raw_price = wc_format_decimal(wp_unslash($_POST['price'] ?? ''));
        $product = wc_get_product($product_id);

        if (!$product || '' === $raw_price || (float)$raw_price < 0) {
            wp_send_json_error(['message' => 'قیمت معتبر نیست.']);
        }
        if ($product->is_type('variable')) {
            wp_send_json_error(['message' => 'برای محصول متغیر، قیمت را روی Variation موردنظر اعمال کنید.']);
        }

        $product->set_regular_price($raw_price);
        $product->set_price($raw_price);
        $product->save();

        wp_send_json_success([
            'price' => (float)$raw_price,
            'formatted' => wp_strip_all_tags(wc_price((float)$raw_price)),
            'message' => 'قیمت فروشگاه با تایید شما بروزرسانی شد.',
        ]);
    }

    private function source_cell($product_id, $source) {
        $cfg = $this->source_config($source);
        $match = (array) get_post_meta($product_id, '_pca_match_' . $source, true);
        $price = (array) get_post_meta($product_id, '_pca_price_' . $source, true);
        $score = isset($match['score']) ? (int)$match['score'] : 0;

        ob_start();
        ?>
        <section class="pca-market pca-market-<?php echo esc_attr($source); ?>" data-source="<?php echo esc_attr($source); ?>">
            <div class="pca-market-top">
                <div class="pca-market-brand">
                    <span class="pca-market-dot"></span>
                    <div><strong><?php echo esc_html($cfg['label']); ?></strong><small><?php echo $score ? esc_html($score . '٪ تطبیق') : 'جستجوی هوشمند نام'; ?></small></div>
                </div>
                <button type="button" class="pca-icon-btn pca-search-source" title="جستجوی دوباره">↻</button>
            </div>

            <div class="pca-source-price">
                <?php if (!empty($price['price'])): ?>
                    <b><?php echo wp_kses_post(wc_price((float)$price['price'])); ?></b>
                    <small><?php echo !empty($price['checked_at']) ? 'بروزرسانی ' . esc_html(wp_date('Y/m/d H:i', (int)$price['checked_at'])) : ''; ?></small>
                <?php else: ?>
                    <b class="pca-price-empty">—</b><small>برای دریافت قیمت جستجو کنید</small>
                <?php endif; ?>
            </div>

            <div class="pca-source-match">
                <?php if (!empty($match['url'])): ?>
                    <a href="<?php echo esc_url($match['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($match['title'] ?: 'محصول انتخاب‌شده'); ?></a>
                    <button type="button" class="pca-text-btn pca-refresh-source">بروزرسانی قیمت</button>
                <?php else: ?>
                    <span>هنوز محصول مشابه پیدا نشده</span>
                <?php endif; ?>
            </div>
            <div class="pca-suggestions" hidden></div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function products_page() {
        if (!current_user_can('manage_woocommerce')) return;

        $paged = max(1, absint($_GET['paged'] ?? 1));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $args = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 12,
            'paged' => $paged,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ];
        if ($search) $args['s'] = $search;

        $query = new WP_Query($args);
        ?>
        <div class="wrap pca-admin pca-products-page">
            <section class="pca-page-hero">
                <div>
                    <span class="pca-eyebrow">PRICE INTELLIGENCE</span>
                    <h1>محصولات و مقایسه قیمت</h1>
                    <p>قیمت فروشگاه را کنار نزدیک‌ترین نتیجه دیجی‌کالا، ترب و باسلام ببینید. اولین نتیجه مشابه نام محصول، خودکار انتخاب و قیمتش نمایش داده می‌شود.</p>
                </div>
                <div class="pca-hero-stats">
                    <span><b><?php echo esc_html($query->found_posts); ?></b><small>محصول</small></span>
                    <span><b>3</b><small>منبع قیمت</small></span>
                </div>
            </section>

            <section class="pca-controlbar">
                <form class="pca-products-toolbar" method="get">
                    <input type="hidden" name="page" value="price-compare-assistant-products">
                    <label class="pca-searchbox">
                        <span>⌕</span>
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="نام محصول یا SKU را جستجو کنید…">
                    </label>
                    <button class="button button-primary">جستجو</button>
                    <?php if ($search): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=price-compare-assistant-products')); ?>">حذف فیلتر</a><?php endif; ?>
                </form>
                <div class="pca-control-help">تغییر قیمت فروشگاه فقط با تایید شما انجام می‌شود.</div>
            </section>

            <div class="pca-product-list">
                <?php foreach ($query->posts as $product_id):
                    $product = wc_get_product($product_id);
                    if (!$product) continue;
                    $image = wp_get_attachment_image_url($product->get_image_id(), 'medium');
                    ?>
                    <article class="pca-product-card" data-product="<?php echo esc_attr($product_id); ?>">
                        <header class="pca-product-header">
                            <div class="pca-product-identity">
                                <?php if ($image): ?><img src="<?php echo esc_url($image); ?>" alt=""><?php else: ?><span class="pca-product-placeholder">◫</span><?php endif; ?>
                                <div>
                                    <div class="pca-product-flags">
                                        <span>#<?php echo esc_html($product_id); ?></span>
                                        <?php if ($product->get_sku()): ?><span>SKU: <?php echo esc_html($product->get_sku()); ?></span><?php endif; ?>
                                    </div>
                                    <h2><?php echo esc_html($product->get_name()); ?></h2>
                                    <button type="button" class="pca-primary-action pca-search-all"><span>⌕</span>مقایسه خودکار در هر ۳ سایت</button>
                                </div>
                            </div>

                            <div class="pca-store-price">
                                <small>قیمت فروشگاه شما</small>
                                <strong><?php echo wp_kses_post($product->get_price_html() ?: '—'); ?></strong>
                                <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>">ویرایش محصول</a>
                            </div>
                        </header>

                        <div class="pca-market-grid">
                            <?php echo $this->source_cell($product_id, 'digikala'); ?>
                            <?php echo $this->source_cell($product_id, 'torob'); ?>
                            <?php echo $this->source_cell($product_id, 'basalam'); ?>
                        </div>

                        <footer class="pca-product-footer">
                            <div>
                                <strong>قیمت فروشگاه را تغییر می‌دهید؟</strong>
                                <span>می‌توانید یکی از قیمت‌های پیدا شده را وارد کنید یا مبلغ دلخواه بنویسید.</span>
                            </div>
                            <div class="pca-apply-price">
                                <input type="number" min="0" step="1" placeholder="قیمت جدید">
                                <button type="button" class="button button-primary pca-apply-price-btn">اعمال قیمت</button>
                            </div>
                        </footer>
                    </article>
                <?php endforeach; ?>

                <?php if (!$query->posts): ?>
                    <div class="pca-empty">محصولی با این جستجو پیدا نشد.</div>
                <?php endif; ?>
            </div>

            <?php
            $pages = paginate_links([
                'base' => add_query_arg(['page' => 'price-compare-assistant-products', 'paged' => '%#%', 's' => $search], admin_url('admin.php')),
                'format' => '',
                'current' => $paged,
                'total' => max(1, (int)$query->max_num_pages),
                'type' => 'list',
                'prev_text' => '‹',
                'next_text' => '›',
            ]);
            if ($pages) echo '<nav class="pca-pagination">' . wp_kses_post($pages) . '</nav>';
            ?>
        </div>
        <?php
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = $this->settings();
        ?>
        <div class="wrap pca-admin">
            <div class="pca-hero">
                <div>
                    <h1>دستیار مقایسه قیمت</h1>
                    <p>مقایسه قیمت و محتوای محصول با منابع مختلف و اعمال تغییر فقط با تایید مدیر.</p>
                </div>
                <span>v<?php echo esc_html(self::VERSION); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('pca_group'); ?>
                <div class="pca-grid">
                    <section class="pca-card">
                        <h2>تنظیمات عمومی</h2>
                        <label class="pca-switch">
                            <span>فعال بودن افزونه</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[enabled]" value="1" <?php checked($s['enabled'],'yes'); ?>>
                        </label>

                        <label>روش جستجو
                            <select name="<?php echo self::OPTION; ?>[search_by]">
                                <option value="name" <?php selected($s['search_by'],'name'); ?>>نام محصول</option>
                                <option value="sku" <?php selected($s['search_by'],'sku'); ?>>SKU</option>
                                <option value="name_sku" <?php selected($s['search_by'],'name_sku'); ?>>نام + SKU</option>
                            </select>
                        </label>

                        <label>منابع
                            <input type="text" name="<?php echo self::OPTION; ?>[sources]" value="<?php echo esc_attr($s['sources']); ?>">
                            <small>با کاما جدا کنید؛ مانند digikala,torob,basalam</small>
                        </label>
                    </section>

                    <section class="pca-card">
                        <h2>مقایسه</h2>
                        <label class="pca-switch">
                            <span>پیشنهاد خودکار محصول مشابه</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[auto_suggest]" value="1" <?php checked($s['auto_suggest'],'yes'); ?>>
                        </label>
                        <label class="pca-switch">
                            <span>مقایسه قیمت</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[compare_price]" value="1" <?php checked($s['compare_price'],'yes'); ?>>
                        </label>
                        <label class="pca-switch">
                            <span>مقایسه محتوا و سئو</span>
                            <input type="checkbox" name="<?php echo self::OPTION; ?>[compare_content]" value="1" <?php checked($s['compare_content'],'yes'); ?>>
                        </label>
                    </section>

                    <section class="pca-card">
                        <h2>ظاهر</h2>
                        <label>رنگ اصلی
                            <input type="color" name="<?php echo self::OPTION; ?>[accent]" value="<?php echo esc_attr($s['accent']); ?>">
                        </label>
                    </section>

                    <section class="pca-card">
                        <h2>وضعیت توسعه</h2>
                        <p>هسته تنظیمات و رابط مدیریت آماده است. موتور جستجو، استخراج قیمت/محتوا و دکمه اعمال مستقیم در نسخه‌های بعدی همین Repo تکمیل می‌شود.</p>
                    </section>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>
        </div>
        <?php
    }
}

new PCA_Plugin();
