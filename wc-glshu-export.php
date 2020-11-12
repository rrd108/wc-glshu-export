<?php
/*
Plugin Name: GLS HU Export & Tracking for WooCommerce
Plugin URI: http://webmania.cc
Description: Rendelések export GLS weblabel importhoz és GLS csomagkövetés
Author: rrd
Version: 0.1.2
*/

if (! defined('ABSPATH')) {
    exit;
}

class WC_GLSHU_Export
{
    public static $plugin_prefix;
    public static $plugin_url;
    public static $plugin_path;
    public static $plugin_basename;
    public static $version;
    private $status_codes;

    //Construct
    public function __construct()
    {

        //Default variables
        self::$plugin_prefix = 'wc_glshu_export_';
        self::$plugin_basename = plugin_basename(__FILE__);
        self::$plugin_url = plugin_dir_url(self::$plugin_basename);
        self::$plugin_path = trailingslashit(dirname(__FILE__));
        self::$version = '0.1.2';

        $this->status_codes = [
            1 => 'Irsz & Súly rögzítése beérkezés',
            2 => 'HUB Outbound scan',
            3 => 'Érkezés a depóba',
            4 => 'Kézbesítésre átvéve',
            5 => 'Kiszállítva',
            6 => 'HUB tárolás',
            7 => 'Depó tárolás',
            8 => 'Ügyfeles felvétel',
            9 => 'Meghatározott időpontra történő kiszállítás',
            11 => 'Szabadság',
            12 => 'Átvevő nem található',
            13 => 'Depó továbbítási hiba',
            14 => 'Áruátvétel bezárva',
            15 => 'Időhiány',
            16 => 'Pénzhiány',
            17 => 'Átvétel megtagadása',
            18 => 'Hibás cím',
            19 => 'Megközelíthetetlen',
            20 => 'Rossz irányítószám',
            21 => 'HUB rakodási hiba',
            22 => 'Vissza a HUB-nak',
            23 => 'Vissza a feladónak',
            24 => 'Depó ismételt kiszállítás',
            25 => 'APL-hiba',
            26 => 'HUB-Inbound',
            27 => 'Small Parcel',
            28 => 'HUB Sérült',
            29 => 'Nincs adat',
            30 => 'Sérülten érkezett',
            31 => 'Totálkár beérkezéskor',
            32 => 'Esti kézbesítés',
            33 => 'Időn túli várakoztatás',
            34 => 'Késői szállítás',
            35 => 'Nem rendelték',
            36 => 'Zárt lépcsőház',
            37 => 'Központ utasítására vissza',
            38 => 'Nincs szállítólevél a csomagon',
            39 => 'Nem igazolták le a szállítót',
            43 => 'Eltűnt',
            44 => 'Not Systemlike Parcel',
            46 => 'Átszállítva',
            47 => 'Transferred to subcontractor',
            51 => 'Ügyfeles adat fogadva',
            52 => 'Ügyfeles utánvét adat fogadva COD data sent',
            53 => 'DEPOT TRANSIT',
            55 => 'CsomagPontba letéve',
            56 => 'CsomagPontban tárolva',
            57 => 'CsomagPont visszáru',
            58 => 'Szomszédba kézbesítve',
            80 => 'CHANGD DLIVERYADRES',
            81 => 'RQINFO NORMAL',
            82 => 'REQFWD MISROUTED',
            83 => 'P&S/P&R rögzítve',
            84 => 'P&S/P&R kinyomtatva',
            85 => 'P&S/P&R rollkartén',
            86 => 'P&S/P&R felvéve',
            87 => 'Nincs P&S/P&R csomag',
            88 => 'Küldemény nem áll készen',
            89 => 'Kevesebb csomagcímke',
            90 => 'Feladva más úton',
            91 => 'P&S, P&R törölve',
            94 => 'CsomagPont státusz infó'
        ];

        register_activation_hook( __FILE__, [$this, 'set_cron']);
        add_action('glshu_update_statuses', [$this, 'glshu_update_statuses']);
        register_deactivation_hook( __FILE__, [$this, 'remove_cron']);

        add_filter('bulk_actions-edit-shop_order', [$this, 'register_gls_export']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'gls_export'], 10, 3);

        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('woocommerce_process_shop_order_meta', [&$this, 'save_meta_box'], 0, 2);

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'wc_glshu_export_plugin_links']);

        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_settings_tab_glshuexport', [$this, 'settings_tab']);
        add_action('woocommerce_update_options_settings_tab_glshuexport', [$this, 'update_settings']);
    }

    public function set_cron()
    {
        if (!wp_next_scheduled('glshu_update_statuses')) {
            wp_schedule_event(time(), 'hourly', 'glshu_update_statuses');
        }
    }

    public function remove_cron() {
        wp_clear_scheduled_hook('glshu_update_statuses');
    }

    public function glshu_update_statuses() {

        if (get_option('wc_glshu_auto_status') === 'yes') {
            // collect order ids and gls numbers with shipped status
            $posted_orders = wc_get_orders([
                'limit'=>-1,
                'type'=> 'shop_order',
                'status'=> get_option('wc_glshu_posted', 'wc-posted'),
            ]);
            foreach($posted_orders as $po) {
                foreach($po->meta_data as $meta) {
                    if ($meta->key == '_GLStrackingNumber') {
                        //$posted_orders[$po->id] = $meta->value;

                        // check them one by one for current status
                        $xml = simplexml_load_file('http://online.gls-hungary.com/tt_page_xml.php?pclid=' . $meta->value);
                        if ($xml && $xml->Parcel->Statuses->children()) {
                            $status = $xml->Parcel->Statuses->children()[0]['StCode'];
                            if ($status == 5) {
                                $po->update_status(substr(get_option('wc_glshu_completed', 'wc-completed'),3));
                            }
                            if ($status == 12 || $status == 23) {
                                $po->update_status(substr(get_option('wc_glshu_shipping_error', 'wc-failed'),3));
                            }
                        }
                    }
                }
            }
        }
    }

    public function wc_glshu_export_plugin_links($links)
    {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=settings_tab_glshuexport') . '">'
            . 'Beállítások' . '</a>'
        ];

        return array_merge($plugin_links, $links);
    }

    public function add_settings_tab($settings_tabs)
    {
        $settings_tabs['settings_tab_glshuexport'] = 'GLS HU export';
        return $settings_tabs;
    }

    public function settings_tab()
    {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $statuses['ignore'] =  'ne módosítsd a státuszt';
        $settings = [
            [
                'type' => 'title',
                'title' => 'GLS HU export beállítások',
                'id' => 'wc_glshu_export_options',
                'desc' => 'Státusz beállítások',
            ],
            [
                'title'   => 'Automatikus státusz frissítések engedélyezése',
                'type'    => 'checkbox',
                'id' => 'wc_glshu_auto_status',
                'desc' => 'A beállított eseményeknél a rendelési státuszt a rendszer automatikusan átállítja. Ha ez nincs kipipálva akkor a többi beállítást figyelmen kívűl hagyjuk.',
                'default' => 'no'
            ],
            [
                'id' => 'wc_glshu_posted',
                'title' => 'A feladott csomagok státusza',
                'desc'   => 'Az automata státusz állítás azokra a rendelésekre történik meg amelyek ebben a státuszban vannak. Ez az a státusz aminél a GLS követési szám már meg van adva és a csomagot a futár már átvette.',
                'type' => 'select',
                'options' => $statuses,
                'default' => 'wc-processing'
            ],
            [
                'id' => 'wc_glshu_completed',
                'title' => 'A leszállított rendelés státusza',
                'desc'   => 'A rendelést erre a státuszra állítjuk be ha a GLS szerint a vásárló azt már megkapta.',
                'type' => 'select',
                'options' => $statuses,
                'default' => 'wc-completed'
            ],
            [
                'id' => 'wc_glshu_shipping_error',
                'title' => 'Szállítási hiba státusza',
                'desc'   => 'Ez az a státusz amit akkor állítunk be ha a GLS valami szállítási hibát jelez (Átvevő nem található / Vissza a feladónak)',
                'type' => 'select',
                'options' => $statuses,
                'default' => 'wc-failed'
            ]
        ];

        woocommerce_admin_fields(apply_filters('wc_glshuexport_settings', $settings));
    }

    public function update_settings()
    {
        update_option('wc_glshu_auto_status', $_POST['wc_glshu_auto_status'] ? 'yes' : 'no');
        update_option('wc_glshu_posted', $_POST['wc_glshu_posted']);
        update_option('wc_glshu_completed', $_POST['wc_glshu_completed']);
        update_option('wc_glshu_shipping_error', $_POST['wc_glshu_shipping_error']);
    }

    public function register_gls_export($bulk_actions)
    {
        $bulk_actions['gls_export'] = __('GLS export', 'wc_glshu_export');
        return $bulk_actions;
    }

    public function gls_export($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'gls_export') {
            return $redirect_to;
        }

        $filename = 'gls-'.date('Y-m-d-H-i').'.csv';

        $args = [
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'posts_per_page' => '-1',
            'post__in' => $post_ids
        ];
        $orders = new WP_Query($args);

        $output = fopen("php://output", "w");

        $header = 'utánvét;Név;Cím;Város;irányitószám;email;telefonszám;utánvét hívatkozás;ügyfél hívatkozás;megjegyzés;darabszám;Ország;Kapcsolattartó;Szolgáltatások';
        fputcsv($output, explode(';', $header), ';');

        while ($orders->have_posts()) {
            $orders->the_post();
            $order_id = get_the_ID();
            $order = new WC_Order($order_id);

            $cod = 'cod';
            $csv_row = [];

            // Utánvét összeg
            $csv_row[0] =  ($order->get_payment_method() == $cod) ? $order->get_total() : '';

            // Név
            $csv_row[1] = $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name();

            // Cím
            $csv_row[2] = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();

            // Város
            $csv_row[3] = $order->get_shipping_city();

            // irányitószám
            $csv_row[4] = $order->get_shipping_postcode();

            // email
            $csv_row[5] = $order->get_billing_email();

            // telefonszám
            $csv_row[6] = $order->get_billing_phone();

            // utánvét hívatkozás
            $csv_row[7] = '';

            // ügyfél hívatkozás
            $csv_row[8] = $order->get_order_number();

            // megjegyzés
            $csv_row[9] = str_replace("\n", " ", $order->get_customer_note());

            // darabszám
            $csv_row[10] = 1;

            // Ország
            $csv_row[11] = $order->get_shipping_country() ? $order->get_shipping_country() : 'HU';

            // Kapcsolattartó
            $csv_row[12] = $csv_row[1];

            // Szolgáltatások
            $csv_row[13] = '';
            //$csv_row = apply_filters('wc_glshu_export_item', $csv_row, $order_id);

            fputcsv($output, $csv_row, ';');
        }
        fclose($output);

        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: private", false);
        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"$filename\";");
        header("Content-Transfer-Encoding: binary");

        exit();

        //$redirect_to = add_query_arg('gls_export_orders', count($post_ids), $redirect_to);
        //return $redirect_to;
    }

    //Meta box on order page
    public function add_metabox($post_type)
    {
        add_meta_box('gls_tracking', 'GLS Tracking', array($this, 'render_meta_box_content'), 'shop_order', 'side');
    }

    public function render_meta_box_content($post)
    {
        echo '<input style="width:100%" type="text" id="wc-GLStrackingNumber"
                name="wc-GLStrackingNumber"
                value="' . get_post_meta($post->ID, '_GLStrackingNumber', true) . '">';

        if (get_post_meta($post->ID, '_GLStrackingNumber', true)) {
            echo '<p><a target="_blank" href="https://gls-group.eu/HU/hu/csomagkovetes?match='
            . get_post_meta($post->ID, '_GLStrackingNumber', true) . '">https://gls-group.eu/HU/hu/csomagkovetes?match='
            . get_post_meta($post->ID, '_GLStrackingNumber', true) . '</a><p>';

            $xml = simplexml_load_file('http://online.gls-hungary.com/tt_page_xml.php?pclid=' . get_post_meta($post->ID, '_GLStrackingNumber', true));

            if ($xml && $xml->Parcel->Statuses->children()) {
                echo '<dl>';
                foreach ($xml->Parcel->Statuses->children() as $status) {
                    echo '<dt' . (($status['StCode'] == 5) ? ' style="background:#5b5;color:#fff;font-weight:bold"' : '') . '>' . (string) $status['StDate'] . '</dt>';
                    echo '<dd' . (($status['StCode'] == 5) ? ' style="background:#5b5;color:#fff;font-weight:bold"' : '') . '>' . $this->status_codes[(string) $status['StCode']] . '</dd>';
                }
                echo '</dl>';
            } else {
                echo 'GLS site not accessible';
            }
        }
    }

    public function save_meta_box($post_id, $post)
    {
        if (isset($_POST['wc-GLStrackingNumber'])) {
            update_post_meta($post_id, '_GLStrackingNumber', sanitize_text_field($_POST['wc-GLStrackingNumber']));
        }
    }
}

$GLOBALS['wc_glshu_export'] = new WC_GLSHU_Export();
