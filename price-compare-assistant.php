<?php
/**
 * Plugin Name: دستیار مقایسه قیمت
 * Plugin URI: https://github.com/sahandse/price-compare-assistant
 * Description: مقایسه قیمت و اطلاعات محصولات ووکامرس با منابع خارجی و پیشنهاد بروزرسانی قابل تایید توسط مدیر.
 * Version: 1.0.1
 * Author: Sahand Rezvan
 * Author URI: https://github.com/sahandse
 * Text Domain: price-compare-assistant
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

final class PCA_Plugin {
    const VERSION = '1.0.1';
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
            s_store_register_submenu('price-compare-assistant', 'دستیار مقایسه قیمت', [$this, 'settings_page'], 'manage_woocommerce', 'دستیار مقایسه قیمت');
            return;
        }
        add_submenu_page(
            'woocommerce',
            'دستیار مقایسه قیمت',
            'مقایسه قیمت',
            'manage_woocommerce',
            'price-compare-assistant',
            [$this, 'settings_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos($hook, 'price-compare-assistant')) return;
        wp_enqueue_style('pca-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
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
