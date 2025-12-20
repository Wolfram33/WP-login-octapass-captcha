<?php
/**
 * Plugin Name: WP Login OctaPass Captcha
 * Plugin URI: https://octapass.de
 * Description: Farbbasiertes Captcha-System für WordPress Login - Kostenlos von OctaPass
 * Version: 1.5
 * Author: Rob de Roy
 * Author URI: https://octapass.de
* License: MIT
 * Text Domain: octapass-captcha
 */

// Sicherheitscheck
if (!defined('ABSPATH')) {
    exit;
}

// Session frühzeitig starten, da auf der Login-Seite keine Session automatisch gestartet wird.
// Dies muss vor jeglicher Ausgabe geschehen.
add_action('init', function() {
    if (session_status() === PHP_SESSION_NONE) {
        // Cookie-Parameter explizit setzen für Firefox-Kompatibilität
        // Firefox erfordert SameSite-Attribut, sonst können Session-Cookies bei AJAX-Anfragen
        // ohne vorherige Benutzerinteraktion verloren gehen
        $secure = is_ssl();
        $httponly = true;
        $samesite = 'Lax'; // 'Strict' würde AJAX von admin-ajax.php blockieren
        
        // PHP 7.3+ unterstützt SameSite direkt
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN ?: '',
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite
            ]);
        } else {
            // Fallback für ältere PHP-Versionen
            session_set_cookie_params(
                0,
                (COOKIEPATH ?: '/') . '; SameSite=' . $samesite,
                COOKIE_DOMAIN ?: '',
                $secure,
                $httponly
            );
        }
        
        session_start();
        
        // Session-Cookie erneut senden, um sicherzustellen, dass Browser es akzeptieren
        // Dies ist besonders wichtig für Firefox bei frischen Sessions
        if (!isset($_SESSION['octapass_session_initialized'])) {
            $_SESSION['octapass_session_initialized'] = true;
            // Regenerate Session ID bei erster Initialisierung für Sicherheit
            session_regenerate_id(false);
        }
    }
}, 1); // Hohe Priorität, um sicherzustellen, dass es früh genug läuft.

// Sprachdateien laden
add_action('plugins_loaded', function() {
    load_plugin_textdomain('octapass-captcha', false, dirname(plugin_basename(__FILE__)) . '/languages');
});


class OctaPassCaptcha {
    public function get_colors() {
        return $this->colors;
    }
    public function field_num_colors() {
        $value = get_option('octapass_num_colors', 3);
        echo '<input type="number" name="octapass_num_colors" value="' . esc_attr($value) . '" min="2" max="8" />';
        echo '<p class="description">' . __('Anzahl der Farben pro Aufgabe (2-8)', 'octapass-captcha') . '</p>';
    }
    // Sektion-Callbacks für die Einstellungen
    public function section_general() {
        echo '<p>' . __('Konfigurieren Sie die Schwierigkeit des Captchas.', 'octapass-captcha') . '</p>';
    }

    public function section_colors() {
        echo '<p>' . __('Wählen Sie, welche Farben im Captcha verwendet werden sollen.', 'octapass-captcha') . '</p>';
    }

    public function section_appearance() {
        echo '<p>' . __('Passen Sie das Aussehen des Captchas an.', 'octapass-captcha') . '</p>';
    }

    private $colors = [
        ['name' => 'Rot', 'hex' => '#FF0000'],
        ['name' => 'Blau', 'hex' => '#0000FF'],
        ['name' => 'Grün', 'hex' => '#00FF00'],
        ['name' => 'Orange', 'hex' => '#FFA500'],
        ['name' => 'Schwarz', 'hex' => '#000000'],
        ['name' => 'Weiß', 'hex' => '#FFFFFF'],
        ['name' => 'Gelb', 'hex' => '#FFFF00'],
        ['name' => 'Türkis', 'hex' => '#00FFFF']
    ];
    // Die Farbnamen werden nicht übersetzt, da sie als Schlüssel dienen. Für die Anzeige kann man sie übersetzen, z.B. __('Rot', 'octapass-captcha')

    public function __construct() {
        // Body-Klasse für Loginseite setzen, wenn Captcha aktiv
        add_filter('login_body_class', [$this, 'add_body_class']);
        
        // Das Captcha außerhalb des Login-Formulars anzeigen
        add_action('login_message', [$this, 'display_captcha_wrapper']);

        // JavaScript und CSS für die Login-Seite einreihen
        add_action('login_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('login_enqueue_scripts', [$this, 'enqueue_styles']); // WIEDER AKTIVIEREN

        // Captcha Validierung vor der eigentlichen Authentifizierung
        add_action('authenticate', [$this, 'validate_captcha'], 20, 3); // Höhere Priorität als 30
        
        // Captcha zurücksetzen bei fehlgeschlagenem Login
        add_action('wp_login_failed', [$this, 'handle_login_failed']);
        
        // AJAX-Handler für Captcha-Interaktion
        add_action('wp_ajax_nopriv_octapass_click', [$this, 'handle_captcha_click']);
        add_action('wp_ajax_octapass_click', [$this, 'handle_captcha_click']);
        add_action('wp_ajax_nopriv_octapass_reset', [$this, 'handle_captcha_reset']);
        add_action('wp_ajax_octapass_reset', [$this, 'handle_captcha_reset']);

        // Admin-Bereich
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    
    // Wrapper-Funktion, die entscheidet, ob Captcha oder Login-Formular angezeigt wird.
    public function display_captcha_wrapper() {
        if (!$this->is_captcha_solved()) {
            $this->start_captcha_session();
            $this->render_captcha_form();
            // Keine Inline-Styles mehr, Sichtbarkeit wird durch CSS/JS gesteuert
        }
    }

    // Fügt die Body-Klasse für das Captcha hinzu, solange es nicht gelöst ist
    public function add_body_class($classes) {
        if (!$this->is_captcha_solved()) {
            $classes[] = 'octapass-captcha-active';
        }
        return $classes;
    }

    private function start_captcha_session() {
        // Session sollte bereits durch 'init' Hook gestartet sein
        if (!isset($_SESSION['octapass_task'])) {
            $_SESSION['octapass_task'] = $this->generate_task();
            $_SESSION['octapass_clicks'] = [];
        }
    }

    private function generate_task() {
        $task = [];
        $available_colors = $this->get_active_colors();
        $num_colors = (int) get_option('octapass_num_colors', 3);
        $num_clicks = (int) get_option('octapass_num_clicks', 1);

        // Fehlerbehandlung: Nicht genug Farben ausgewählt
        if (count($available_colors) < $num_colors) {
            if (empty($available_colors)) {
                // Fallback: alle Farben nehmen, falls keine Auswahl gespeichert
                $available_colors = $this->colors;
            } else {
                // Zeige eine Fehlermeldung im Captcha-Formular
                $_SESSION['octapass_task_error'] = sprintf(__('Bitte wählen Sie im Adminbereich mindestens %d aktive Farben für das Captcha aus!', 'octapass-captcha'), $num_colors);
                return [];
            }
        }
        
        // Sicherstellen, dass $num_colors nicht größer ist als die Anzahl der verfügbaren Farben
        $num_colors = min($num_colors, count($available_colors));
        
        // Stellen Sie sicher, dass wir genügend verfügbare Farben zum Mischen haben
        if ($num_colors > 0) {
            $random_colors_keys = (array) array_rand($available_colors, $num_colors);
            // Wenn nur eine Farbe benötigt wird, array_rand gibt einen String zurück, sonst ein Array
            if (!is_array($random_colors_keys)) {
                $random_colors_keys = [$random_colors_keys];
            }

            foreach ($random_colors_keys as $key) {
                $color = $available_colors[$key]['name'];
                $task[$color] = $num_clicks;
            }
        }


        unset($_SESSION['octapass_task_error']);
        return $task;
    }

    private function get_active_colors() {
        $active_colors_names = get_option('octapass_active_colors', array_column($this->colors, 'name'));
        return array_values(array_filter($this->colors, function($color) use ($active_colors_names) {
            return in_array($color['name'], $active_colors_names);
        }));
    }

    private function render_captcha_form() {
        $task = $_SESSION['octapass_task'] ?? [];
        $clicks = $_SESSION['octapass_clicks'] ?? [];
        $total_required = array_sum($task);
        $total_clicked = array_sum($clicks);
        $progress = $total_required > 0 ? ($total_clicked / $total_required) * 100 : 0;

        echo '<div id="octapass-container">';

        // Branding-Link
        if (get_option('octapass_show_branding', 1)) {
            echo '<div class="octapass-branding">';
            echo sprintf(__('Geschützt durch %s', 'octapass-captcha'), '<a href="https://octapass.de" target="_blank" class="octapass-branding-link">OctaPass-Captcha</a>');
            echo '</div>';
        }

        echo '<h3>' . __('Sicherheitsprüfung', 'octapass-captcha') . '</h3>';
        echo '<p>' . esc_html(get_option('octapass_custom_text', __('Klicken Sie die Farben in der angegebenen Anzahl:', 'octapass-captcha'))) . '</p>';

        // Fehleranzeige, falls zu wenig Farben
        if (!empty($_SESSION['octapass_task_error'])) {
            echo '<div class="octapass-task-box" style="color:red; font-weight:bold;">' . htmlspecialchars($_SESSION['octapass_task_error']) . '</div>';
            echo '<button id="octapass-reset" class="octapass-reset-btn">↻ ' . __('Neue Aufgabe', 'octapass-captcha') . '</button>';
            echo '</div>';
            return;
        }

        // Aufgabe anzeigen
        echo '<div class="octapass-task-box">';
        if (empty($task)) { // Fallback, falls generate_task() ein leeres Array zurückgibt
            echo '<div>' . __('Es gab ein Problem beim Generieren der Aufgabe. Bitte versuchen Sie es erneut.', 'octapass-captcha') . '</div>';
        } else {
            // Zeige für jede geforderte Farbe: Farbfeld + Name + Status (immer sichtbar)
            foreach ($task as $color => $count) {
                $current_clicks = $clicks[$color] ?? 0;
                $done = $current_clicks >= $count;
                $status = $done ? __('Fertig', 'octapass-captcha') : ($current_clicks . ' / ' . $count);
                // Hole Farbwert für Swatch
                $color_obj = null;
                foreach ($this->colors as $c) {
                    if ($c['name'] === $color) {
                        $color_obj = $c;
                        break;
                    }
                }
                $swatch = $color_obj ? '<span class="octapass-color-swatch" style="display:inline-block;width:18px;height:18px;border-radius:50%;border:1px solid #888;margin-right:6px;vertical-align:middle;background:' . esc_attr($color_obj['hex']) . ';"></span>' : '';
                echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">' . $swatch . '<span style="min-width:60px;display:inline-block;font-weight:bold;">' . esc_html(__($color, 'octapass-captcha')) . '</span>: <span style="font-weight:' . ($done ? 'bold' : 'normal') . ';color:' . ($done ? '#2e7d32' : '#222') . ';">' . $status . '</span></div>';
            }
        }
        echo '</div>';

        // Farbbuttons
        echo '<div id="color-buttons" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;">';
        foreach ($this->get_active_colors() as $color) {
            $color_label = esc_html(__($color['name'], 'octapass-captcha'));
            // Automatische Schriftfarbe je nach Hintergrundhelligkeit
            $hex = ltrim($color['hex'], '#');
            if (strlen($hex) === 6) {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                // YIQ brightness formula
                $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
                $text_color = ($brightness < 128) ? '#fff' : '#111';
            } else {
                $text_color = '#111';
            }
            // Die Buttons behalten IMMER Farbswatch + Namen, unabhängig vom Status
            echo '<button class="octapass-color-btn" data-color="' . $color['name'] . '" style="background:' . $color['hex'] . ';display:flex;flex-direction:column;align-items:center;justify-content:center;width:60px;height:60px;border-radius:8px;border:2px solid #888;font-size:15px;gap:4px;" title="' . $color_label . '" aria-label="' . $color_label . '">';
            echo '<span aria-hidden="true" style="display:block;width:24px;height:24px;border-radius:50%;background:' . $color['hex'] . ';border:1px solid #666;margin-bottom:2px;"></span>';
            echo '<span style="font-size:13px;line-height:1;color:' . $text_color . ';font-weight:bold;">' . $color_label . '</span>';
            echo '</button>';
        }
        echo '</div>';

        // Fortschrittsbalken
        echo '<div class="octapass-progress-bar-outer">';
        echo '<div class="octapass-progress-bar-inner" style="width:' . round($progress) . '%;"></div>';
        echo '</div>';

        // Reset Button
        echo '<button id="octapass-reset" class="octapass-reset-btn">↻ ' . __('Neue Aufgabe', 'octapass-captcha') . '</button>';
        echo '</div>';
    }

    public function enqueue_scripts() {
        // Enqueue JavaScript nur auf der Login-Seite
        if (in_array($GLOBALS['pagenow'], array('wp-login.php'))) {
            wp_enqueue_script('jquery'); // jQuery ist in WordPress standardmäßig vorhanden
            wp_enqueue_script(
                'octapass-captcha-js',
                plugins_url('octapass-captcha.js', __FILE__),
                ['jquery'], // Abhängigkeit von jQuery
                filemtime(plugin_dir_path(__FILE__) . 'octapass-captcha.js'), // Versionierung basierend auf Dateimodifikationszeit
                true // Im Footer laden
            );

            // Lokalisierung für AJAX-URL und Captcha-Status
            wp_localize_script('octapass-captcha-js', 'octapass_vars', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'is_solved' => $this->is_captcha_solved() ? 'true' : 'false', // Übergib den Lösungsstatus an JS
                'error_message' => __('Das Captcha ist nicht korrekt gelöst. Bitte versuchen Sie es erneut.', 'octapass-captcha')
            ));
        }
    }

    public function enqueue_styles() {
        // Enqueue CSS nur auf der Login-Seite
        if (in_array($GLOBALS['pagenow'], array('wp-login.php'))) {
            wp_enqueue_style(
                'octapass-captcha-css',
                plugins_url('octapass-captcha.css', __FILE__),
                array(), // Keine Abhängigkeiten
                filemtime(plugin_dir_path(__FILE__) . 'octapass-captcha.css') // Versionierung
            );
        }
    }

    public function enqueue_admin_scripts($hook) {
        // Nur auf der Plugin-Einstellungsseite laden
        if (isset($_GET['page']) && $_GET['page'] === 'octapass-captcha') {
            wp_enqueue_script(
                'octapass-admin-preview',
                plugins_url('admin-preview.js', __FILE__),
                [],
                filemtime(plugin_dir_path(__FILE__) . 'admin-preview.js'),
                true
            );
            // Admin-CSS wird nur eingebunden, wenn die Datei existiert
            $admin_css_path = plugin_dir_path(__FILE__) . 'octapass-admin.css';
            if (file_exists($admin_css_path)) {
                wp_enqueue_style(
                    'octapass-admin-css',
                    plugins_url('octapass-admin.css', __FILE__),
                    array(),
                    filemtime($admin_css_path)
                );
            }
        }
    }

    public function handle_captcha_click() {
        // session_start() ist bereits im 'init' Hook gesichert
        $color = sanitize_text_field($_POST['color']);

        // Reihenfolge der Aufgabe bestimmen
        $task_order = array_keys($_SESSION['octapass_task'] ?? []);
        $clicks = $_SESSION['octapass_clicks'] ?? [];
        $current_step = 0;
        foreach ($task_order as $i => $task_color) {
            $required = $_SESSION['octapass_task'][$task_color];
            $clicked = $clicks[$task_color] ?? 0;
            if ($clicked < $required) {
                $current_step = $i;
                break;
            }
        }

        // Die aktuelle Farbe, die als nächstes geklickt werden muss
        $expected_color = $task_order[$current_step] ?? null;

        // Prüfe, ob die geklickte Farbe Teil der Aufgabe ist und ob die Reihenfolge stimmt
        if (!isset($_SESSION['octapass_task'][$color]) || $color !== $expected_color) {
            // Falsche Farbe oder falsche Reihenfolge: Captcha zurücksetzen
            unset($_SESSION['octapass_task']);
            unset($_SESSION['octapass_clicks']);
            unset($_SESSION['octapass_solved']);
            wp_send_json_success(array(
                'is_solved' => false,
                'clicks' => [],
                'task' => [],
                'active_colors' => $this->get_active_colors(),
                'error' => __('Falsche Farbe oder Reihenfolge! Das Captcha wurde zurückgesetzt.', 'octapass-captcha')
            ));
            wp_die();
        }

        if (!isset($_SESSION['octapass_clicks'][$color])) {
            $_SESSION['octapass_clicks'][$color] = 0;
        }

        // Stelle sicher, dass nicht über die benötigte Anzahl hinaus geklickt wird
        $required_clicks = $_SESSION['octapass_task'][$color] ?? 0;
        if ($_SESSION['octapass_clicks'][$color] < $required_clicks) {
            $_SESSION['octapass_clicks'][$color]++;
        }

        $is_solved = $this->is_captcha_solved();
        wp_send_json_success(array(
            'is_solved' => $is_solved,
            'clicks' => $_SESSION['octapass_clicks'],
            'task' => $_SESSION['octapass_task'],
            'active_colors' => $this->get_active_colors()
        ));
        wp_die();
    }

    public function handle_captcha_reset() {
        // session_start() ist bereits im 'init' Hook gesichert
        unset($_SESSION['octapass_task']);
        unset($_SESSION['octapass_clicks']);
        unset($_SESSION['octapass_solved']);

        // Eine neue Aufgabe generieren und direkt zurückgeben
        $this->start_captcha_session();
        $new_task = $_SESSION['octapass_task'] ?? [];
        $active_colors = $this->get_active_colors();
        
        wp_send_json_success(array(
            'new_task' => $new_task,
            'active_colors' => $active_colors,
            'clicks' => [],
            'is_solved' => false // Nach Reset ist es natürlich nicht gelöst
        ));
        wp_die();
    }

    public function validate_captcha($user, $username, $password) {

        if (isset($_POST['wp-submit']) && !$this->is_captcha_solved()) {
            return new WP_Error('captcha_required', '<strong>' . __('FEHLER', 'octapass-captcha') . '</strong>: ' . __('Bitte lösen Sie zuerst das Captcha.', 'octapass-captcha'));
        }

        // Wenn das Captcha gelöst ist, das octapass_solved Flag zurücksetzen,
        // damit es beim nächsten Login erneut gelöst werden muss.
        if ($this->is_captcha_solved()) {
             unset($_SESSION['octapass_solved']);
             unset($_SESSION['octapass_task']);
             unset($_SESSION['octapass_clicks']);
        }
        
        return $user;
    }

    private function is_captcha_solved() {
        // session_start() ist bereits im 'init' Hook gesichert

        if (isset($_SESSION['octapass_solved']) && $_SESSION['octapass_solved'] === true) {
            return true;
        }

        if (!isset($_SESSION['octapass_task']) || !isset($_SESSION['octapass_clicks'])) {
            return false;
        }

        // Prüfe, ob eine Farbe geklickt wurde, die nicht zur Aufgabe gehört
        foreach ($_SESSION['octapass_clicks'] as $clicked_color => $count) {
            if (!isset($_SESSION['octapass_task'][$clicked_color])) {
                return false;
            }
        }

        foreach ($_SESSION['octapass_task'] as $color => $required) {
            $clicked = $_SESSION['octapass_clicks'][$color] ?? 0;
            if ($clicked !== $required) { // Muss exakt die Anzahl sein
                return false;
            }
        }

        // Wenn alle Bedingungen erfüllt sind, ist das Captcha gelöst
        $_SESSION['octapass_solved'] = true;
        return true;
    }

    public function handle_login_failed() {
        // Captcha nach fehlgeschlagenem Login zurücksetzen
        // session_start() ist bereits im 'init' Hook gesichert
        unset($_SESSION['octapass_task']);
        unset($_SESSION['octapass_clicks']);
        unset($_SESSION['octapass_solved']);
    }

    // ==================== ADMIN-BEREICH ====================

    public function add_admin_menu() {
        add_options_page(
            'OctaPass Captcha Einstellungen',
            'OctaPass Captcha',
            'manage_options',
            'octapass-captcha',
            [$this, 'admin_page']
        );
    }

    public function admin_init() {
        // Entfernt: min/max Farben und Klicks, nur noch feste Werte
        register_setting('octapass_settings', 'octapass_active_colors', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_active_colors'], 'default' => array_column($this->colors, 'name')]);
        register_setting('octapass_settings', 'octapass_show_branding', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => 1]);
        register_setting('octapass_settings', 'octapass_custom_text', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Klicken Sie die Farben in der angegebenen Anzahl:']);
        register_setting('octapass_settings', 'octapass_num_colors', [
            'type' => 'number',
            'sanitize_callback' => function($val) {
                $val = absint($val);
                if ($val < 2) $val = 2;
                if ($val > 8) $val = 8;
                return $val;
            },
            'default' => 3
        ]);
        register_setting('octapass_settings', 'octapass_num_clicks', ['type' => 'number', 'sanitize_callback' => 'absint', 'default' => 1]);

        add_settings_section(
            'octapass_general',
            'Allgemeine Einstellungen',
            [$this, 'section_general'],
            'octapass_settings'
        );

        add_settings_section(
            'octapass_colors',
            'Farben-Konfiguration',
            [$this, 'section_colors'],
            'octapass_settings'
        );

        add_settings_section(
            'octapass_appearance',
            'Aussehen',
            [$this, 'section_appearance'],
            'octapass_settings'
        );

        // Nur noch feste Felder für Anzahl Farben und Klicks
        add_settings_field(
            'num_colors',
            'Anzahl Farben pro Aufgabe',
            [$this, 'field_num_colors'],
            'octapass_settings',
            'octapass_general'
        );

        add_settings_field(
            'num_clicks',
            'Klicks pro Farbe',
            [$this, 'field_num_clicks'],
            'octapass_settings',
            'octapass_general'
        );

        // Felder für Farben
        add_settings_field(
            'active_colors',
            'Aktive Farben',
            [$this, 'field_active_colors'],
            'octapass_settings',
            'octapass_colors'
        );

        // Felder für Aussehen
        add_settings_field(
            'show_branding',
            'OctaPass-Branding anzeigen',
            [$this, 'field_show_branding'],
            'octapass_settings',
            'octapass_appearance'
        );

        add_settings_field(
            'custom_text',
            'Eigener Text',
            [$this, 'field_custom_text'],
            'octapass_settings',
            'octapass_appearance'
        );
    }
    
    public function sanitize_active_colors($input) {
        $valid_colors = array_column($this->colors, 'name');
        $sanitized_input = [];
        if (is_array($input)) {
            foreach ($input as $color_name) {
                if (in_array($color_name, $valid_colors)) {
                    $sanitized_input[] = sanitize_text_field($color_name);
                }
            }
        }
        // Stelle sicher, dass mindestens so viele Farben ausgewählt sind wie num_colors
        $num_colors_setting = (int) get_option('octapass_num_colors', 3);
        if (count($sanitized_input) < $num_colors_setting) {
            add_settings_error(
                'octapass_active_colors',
                'min_colors_error',
                sprintf(__('Sie müssen mindestens %d Farben auswählen.', 'octapass-captcha'), $num_colors_setting),
                'error'
            );
            return array_column($this->colors, 'name'); // Zurück zur Standardauswahl
        }
        return $sanitized_input;
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <div style="margin-bottom: 18px;">
                <img src="<?php echo esc_url(plugins_url('octapass-captcha-banner.png', __FILE__)); ?>" alt="OctaPass Captcha Banner" style="max-width:100%;height:auto;display:block;">
            </div>
            <h1><span class="dashicons dashicons-shield" style="color: #0073aa;"></span> OctaPass Captcha</h1>
            <div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h2>📊 Statistiken</h2>
                <?php
                if ( ! function_exists( 'get_file_data' ) ) {
                    require_once ABSPATH . 'wp-includes/functions.php';
                }
                $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
                $plugin_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '';
                ?>
                <p><strong>Plugin-Version:</strong> <?php echo esc_html($plugin_version); ?></p>
                <p><strong>Installiert seit:</strong> <?php echo get_option('octapass_installation_date', 'Unbekannt'); ?></p>
                <p><strong>Aktive Farben:</strong> <?php echo count(get_option('octapass_active_colors', [])); ?></p>
                <hr>
                <p>💝 <strong>Danke für die Nutzung von OctaPass Captcha!</strong></p>
                <p>
                    <a href="https://octapass.de" target="_blank" class="button">🌐 OctaPass Website</a>
                    <a href="https://74help.de" target="_blank" class="button">💬 Support</a>
                </p>
            </div>
            <form method="post" action="options.php" id="octapass-admin-form">
                <?php
                settings_fields('octapass_settings');
                do_settings_sections('octapass_settings');
                submit_button();
                ?>
            </form>
            <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3>🧪 Captcha-Vorschau</h3>
                <p>So sieht Ihr Captcha mit den aktuellen Einstellungen aus:</p>
                <div style="background: #eaf6ff; border: 1px solid #b6e0fe; color: #155a8a; padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; font-size: 15px; display: flex; align-items: flex-start; gap: 8px;">
                    <span style="font-size:18px;line-height:1.2;">ℹ️</span>
                    <span><?php echo __('Hinweis: Die Vorschau ist nur ein ungefähres Abbild des tatsächlich generierten Captchas. Aufgrund der dynamischen Aufgaben-Erzeugung und vieler Zufallsereignisse ist keine 1:1-Vorschau möglich.', 'octapass-captcha'); ?></span>
                </div>
                <div id="octapass-admin-preview" style="text-align: center; padding: 20px; background: white; border: 1px solid #ddd; max-width: 480px; margin: 0 auto;">
                <?php
                $active_colors_preview = $this->get_active_colors();
                $num_colors = (int) get_option('octapass_num_colors', 3);
                $num_clicks = (int) get_option('octapass_num_clicks', 1);
                // Beispiel-Aufgabe generieren
                $task_colors = array_slice($active_colors_preview, 0, $num_colors);
                $task = [];
                foreach ($task_colors as $color) {
                    $task[$color['name']] = $num_clicks;
                }
                $clicks = [];
                foreach ($task as $color => $count) {
                    $clicks[$color] = 0; // Vorschau: noch nichts geklickt
                }
                $total_required = array_sum($task);
                $total_clicked = array_sum($clicks);
                $progress = $total_required > 0 ? ($total_clicked / $total_required) * 100 : 0;
                ?>
                <div id="octapass-container">
                <?php if (get_option('octapass_show_branding', 1)) {
                    echo '<div class="octapass-branding">' . sprintf(__('Geschützt durch %s', 'octapass-captcha'), '<a href="https://octapass.de" target="_blank" class="octapass-branding-link">OctaPass-Captcha</a>') . '</div>';
                }
                ?>
                <h3><?php echo __('Sicherheitsprüfung', 'octapass-captcha'); ?></h3>
                <p><?php echo esc_html(get_option('octapass_custom_text', __('Klicken Sie die Farben in der angegebenen Anzahl:', 'octapass-captcha'))); ?></p>
                <div class="octapass-task-box">
                <?php
                if (empty($task)) {
                    echo '<div>' . __('Es gab ein Problem beim Generieren der Aufgabe. Bitte wählen Sie aktive Farben.', 'octapass-captcha') . '</div>';
                } else {
                    foreach ($task as $color => $count) {
                        $current_clicks = $clicks[$color] ?? 0;
                        $done = $current_clicks >= $count;
                        $status = $done ? __('Fertig', 'octapass-captcha') : ($current_clicks . ' / ' . $count);
                        $color_obj = null;
                        foreach ($active_colors_preview as $c) {
                            if ($c['name'] === $color) {
                                $color_obj = $c;
                                break;
                            }
                        }
                        $swatch = $color_obj ? '<span class="octapass-color-swatch" style="display:inline-block;width:18px;height:18px;border-radius:50%;border:1px solid #888;margin-right:6px;vertical-align:middle;background:' . esc_attr($color_obj['hex']) . ';"></span>' : '';
                        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;justify-content:center;">' . $swatch . '<span style="min-width:60px;display:inline-block;font-weight:bold;">' . esc_html(__($color, 'octapass-captcha')) . '</span>: <span style="font-weight:' . ($done ? 'bold' : 'normal') . ';color:' . ($done ? '#2e7d32' : '#222') . ';">' . $status . '</span></div>';
                    }
                }
                ?>
                </div>
                <div id="color-buttons" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;justify-content:center;">
                <?php
                foreach ($active_colors_preview as $color) {
                    $color_label = esc_html(__($color['name'], 'octapass-captcha'));
                    $hex = ltrim($color['hex'], '#');
                    if (strlen($hex) === 6) {
                        $r = hexdec(substr($hex, 0, 2));
                        $g = hexdec(substr($hex, 2, 2));
                        $b = hexdec(substr($hex, 4, 2));
                        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
                        $text_color = ($brightness < 128) ? '#fff' : '#111';
                    } else {
                        $text_color = '#111';
                    }
                    echo '<button class="octapass-color-btn" type="button" data-color="' . $color['name'] . '" style="background:' . $color['hex'] . ';display:flex;flex-direction:column;align-items:center;justify-content:center;width:60px;height:60px;border-radius:8px;border:2px solid #888;font-size:15px;gap:4px;cursor:default;" title="' . $color_label . '" aria-label="' . $color_label . '" disabled>';
                    echo '<span aria-hidden="true" style="display:block;width:24px;height:24px;border-radius:50%;background:' . $color['hex'] . ';border:1px solid #666;margin-bottom:2px;"></span>';
                    echo '<span style="font-size:13px;line-height:1;color:' . $text_color . ';font-weight:bold;">' . $color_label . '</span>';
                    echo '</button>';
                }
                ?>
                </div>
                <div class="octapass-progress-bar-outer" style="background:#eee;height:8px;border-radius:4px;margin-bottom:10px;">
                    <div class="octapass-progress-bar-inner" style="width:<?php echo round($progress); ?>%;background:#0073aa;height:100%;border-radius:4px;"></div>
                </div>
                <button id="octapass-reset" class="octapass-reset-btn" type="button" disabled style="opacity:0.5;cursor:default;">↻ <?php echo __('Neue Aufgabe', 'octapass-captcha'); ?></button>
                </div>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var colorCheckboxes = document.querySelectorAll('input[name="octapass_active_colors[]"]');
                colorCheckboxes.forEach(function(checkbox) {
                    checkbox.addEventListener('change', function() {
                        document.getElementById('octapass-admin-form').submit();
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function field_num_clicks() {
        $value = get_option('octapass_num_clicks', 1);
        echo '<input type="number" name="octapass_num_clicks" value="' . esc_attr($value) . '" min="1" max="5" />';
        echo '<p class="description">' . __('Klicks pro Farbe (1-5)', 'octapass-captcha') . '</p>';
    }

    public function field_active_colors() {
        $active = get_option('octapass_active_colors', array_column($this->colors, 'name'));

        echo '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">';
        foreach ($this->colors as $color) {
            $checked = in_array($color['name'], $active) ? 'checked' : '';
            echo '<label style="display: flex; align-items: center; gap: 10px;">';
            echo '<input type="checkbox" name="octapass_active_colors[]" value="' . esc_attr($color['name']) . '" ' . $checked . ' />';
            echo '<div style="width: 30px; height: 30px; border-radius: 50%; background: ' . esc_attr($color['hex']) . '; border: 2px solid #999;"></div>';
            echo '<span>' . esc_html(__($color['name'], 'octapass-captcha')) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">' . __('Wählen Sie mindestens so viele Farben aus, wie die minimale Anzahl Farben im Captcha beträgt.', 'octapass-captcha') . '</p>';
    }

    public function field_show_branding() {
        $value = get_option('octapass_show_branding', 1);
        echo '<input type="checkbox" name="octapass_show_branding" value="1" ' . checked(1, $value, false) . ' />';
        echo '<p class="description">' . __('Zeigt "Geschützt durch OctaPass-Captcha" über dem Captcha an.', 'octapass-captcha') . '</p>';
        echo '<div style="margin-top:8px;">'
           .'<a href="https://buymeacoffee.com/robderoy" target="_blank" style="font-weight:bold; color:#003366; text-decoration:none;">'
           . __('Wenn du das Branding entfernst, wäre es eine gute Idee, mir einen Kaffee zu spendieren.', 'octapass-captcha') . '</a>';
        // PayPal Donation Link
        echo '<br><a href="https://www.paypal.com/donate?business=robderoy@protonmail.ch" target="_blank" style="font-weight:bold; color:#0070ba; text-decoration:none;">'
           . __('Oder unterstütze mich via PayPal: robderoy@protonmail.ch', 'octapass-captcha') . '</a>';
        echo '</div>';
    }

    public function field_custom_text() {
        $value = get_option('octapass_custom_text', 'Klicken Sie die Farben in der angegebenen Anzahl:');
        echo '<input type="text" name="octapass_custom_text" value="' . esc_attr($value) . '" style="width: 100%; max-width: 400px;" />';
        echo '<p class="description">' . __('Anweisungstext für die Benutzer.', 'octapass-captcha') . '</p>';
    }
}

// Plugin aktivieren
new OctaPassCaptcha();

// Plugin-Aktivierung
register_activation_hook(__FILE__, function() {
    add_option('octapass_installation_date', current_time('mysql'));
    // Set default active colors if not already set
    if (get_option('octapass_active_colors') === false) {
        $default_colors = (new OctaPassCaptcha())->get_colors();
        add_option('octapass_active_colors', array_column($default_colors, 'name'));
    }
    // Set default values for other options if not set
    add_option('octapass_show_branding', 1);
    add_option('octapass_custom_text', 'Klicken Sie die Farben in der angegebenen Anzahl:');
    add_option('octapass_num_colors', 3);
    add_option('octapass_num_clicks', 1);
});

// Plugin-Deaktivierung
register_deactivation_hook(__FILE__, function() {
    // Cleanup wenn nötig - Sessions bereinigen
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['octapass_task']);
        unset($_SESSION['octapass_clicks']);
        unset($_SESSION['octapass_solved']);
    }
});

// Admin-Notiz für Plugin-Seite
add_filter('plugin_row_meta', function($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://octapass.de" target="_blank">🌐 OctaPass Website</a>';
        $links[] = '<a href="https://74help.de" target="_blank">💬 Support</a>';
    }
    return $links;
}, 10, 2);
?>