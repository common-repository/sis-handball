<?php

/**
 * @link http://felixwelberg.de
 * @since 1.0.0
 *
 * @author Felix Welberg <felix@welberg.de>
 */
class Sis_Handball_Public
{

    /**
     * The ID of this plugin.
     *
     * @since 1.0.0
     * @var string $sis_handball
     */
    private $sis_handball;

    /**
     * The version of this plugin.
     *
     * @since 1.0.0
     * @var string $version
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since 1.0.0
     * @param string $sis_handball
     * @param string $version
     */
    public function __construct($sis_handball, $version)
    {
        $this->sis_handball = $sis_handball;
        $this->version = $version;
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since 1.0.0
     */
    public function enqueue_styles()
    {
        if (get_option('sis-handball-default-styles') == 1) {
            wp_enqueue_style($this->sis_handball, plugin_dir_url(__FILE__) . 'css/sis-handball-public.css', [], $this->version, 'all');
        }
    }

    /**
     * Register the FE javascript
     *
     * @since 1.0.7
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script($this->sis_handball . '-google-charts', '//www.gstatic.com/charts/loader.js', [], $this->version, 'all');
        wp_enqueue_script($this->sis_handball, plugin_dir_url(__FILE__) . 'js/sis-handball-public.js', ['jquery'], $this->version, 'all');
    }

    /**
     * Called by shortcode to build FE table
     *
     * @since 1.0.0
     * @param array $atts
     * @param string $content
     * @param bool $data_only
     * @return string | array
     */
    public static function shortcode_sis_handball($atts, $content = '', $tag = '', $data_only = false)
    {
        $atts = apply_filters('sis_handball_atts', $atts);

        $league_id = $atts['league'];
        $type = $atts['type'];
        $team = $atts['team'];
        $url = Sis_Handball_Public::url_builder($type, $league_id);

        if ($data_only == true) {
            // Only return array and no html
            // Check if cache is active
            if (get_option('sis-handball-cache') == 1 && Sis_Handball_Public::additional_param_cache($atts) != 'no-cache') {
                $cached_results = Sis_Handball_Public::load_from_cache($url, $type);
                if ($cached_results) {
                    $results = unserialize($cached_results->cache);
                } else {
                    $results = Sis_Handball_Public::prepare_data_preprocessor($url, $type);
                    Sis_Handball_Public::write_to_cache($results, $url, $type);
                }
            } else {
                $results = Sis_Handball_Public::prepare_data_preprocessor($url, $type);
            }
            return $results;
        }
        if ($type == 'chart') {
            $chart_data = Sis_Handball_Public::position_monitoring($url, $team);
            return Sis_Handball_Public::chart_factory($chart_data);
        }
        if ($type == 'concat') {
            return Sis_Handball_Public::get_concatenation_data($atts['id']);
        }
        // Override results, if they should be loaded from a saved snapshot
        if ($atts['snapshot']) {
            $snapshot_results = Sis_Handball_Public::load_from_snapshot($atts['snapshot']);
            if ($snapshot_results) {
                $results = $snapshot_results;
            } else {
                $results = false;
            }
        } else {
            // Check if cache is active
            if (get_option('sis-handball-cache') == 1 && Sis_Handball_Public::additional_param_cache($atts) != 'no-cache') {
                $cached_results = Sis_Handball_Public::load_from_cache($url, $type);
                if ($cached_results) {
                    $results = unserialize($cached_results->cache);
                } else {
                    $results = Sis_Handball_Public::prepare_data_preprocessor($url, $type);
                    Sis_Handball_Public::write_to_cache($results, $url, $type);
                }
            } else {
                $results = Sis_Handball_Public::prepare_data_preprocessor($url, $type);
            }
        }

        if ($atts['sorting'] == 'desc' && $results) {
            $results = array_reverse($results);
        }

        if (is_numeric($atts['limit']) && $results && (!get_option('sis-handball-lazyload-limit') || Sis_Handball_Public::additional_param_ignore_lazy_loading($atts))) {
            $results = array_slice($results, 0, $atts['limit']);
        }

        if (get_option('sis-handball-cache') == 1 && get_option('sis-handball-show-cache-update') == 1) {
            return Sis_Handball_Public::add_last_cache_update_time(Sis_Handball_Public::table_factory($results, $atts), $cached_results);
        }
        return Sis_Handball_Public::table_factory($results, $atts);
    }

    /**
     * Monitors positioning of a single team
     *
     * @since 1.0.7
     * @param string $url
     * @param string $team
     * @return array
     */
    public static function position_monitoring($url = '', $team = '')
    {
        global $wpdb;
        $cache_minus = Sis_Handball_Public::get_cache_timeout();
        $cache_time = time() - $cache_minus;
        $check_current_data = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'sis_monitoring WHERE team = "' . $team . '" AND url = "' . $url . '"  ORDER BY id DESC LIMIT 1');
        if ($check_current_data == null || $check_current_data->monitoring_time < $cache_time) {
            $data = Sis_Handball_Public::prepare_data_preprocessor($url);
            $data_to_save = [];
            $search_result = [];
            foreach ($data as $dataset) {
                if (array_search($team, $dataset)) {
                    $search_result = $dataset;
                }
            }
            if ($search_result) {
                $data_to_save['position'] = $search_result[0];
                $data_to_save['team'] = $search_result[1];
                $data_to_save['gameday'] = $search_result[2];
                $data_to_save['url'] = $url;
            }
            Sis_Handball_Public::save_position_monitoring($data_to_save);
        }

        /* url matching (old sis -> new sis) for monitoring logs before 08 June 2017 09:20 */
        $url_old = Sis_Handball_Public::sis_url_migration($url);
        $tracked_data_old_type = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sis_monitoring WHERE team = "' . $team . '" AND url = "' . $url_old . '" AND monitoring_time <= "1496910000" ORDER BY id ASC');
        if ($tracked_data_old_type) {
            $tracked_data = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sis_monitoring WHERE team = "' . $team . '" AND url = "' . $url_old . '" AND gameday > 0 ORDER BY id ASC');
        } else {
            $tracked_data = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sis_monitoring WHERE team = "' . $team . '" AND url = "' . $url . '" AND gameday > 0 ORDER BY id ASC');
        }
        $chart_data = [];
        if ($tracked_data) {
            $chart_data['team'] = $team;
            foreach ($tracked_data as $key => $tracked_dataset) {
                $chart_data['chart'][$key]['gameday'] = $tracked_dataset->gameday;
                $chart_data['chart'][$key]['position'] = $tracked_dataset->position;
            }
        }
        return $chart_data;
    }

    /**
     * Returns old sis url by given new sis url
     *
     * @since 1.0.23
     * @param type $new_url
     * @return bool|string
     */
    public static function sis_url_migration($new_url = '')
    {
        if ($new_url) {
            $new_url_exp = explode('view=', $new_url);
            $new_url_exp = explode('&', $new_url_exp[1]);
            switch ($new_url_exp[0]) {
                case 'Mannschaft': return 'http://sis-handball.de/web/Mannschaft/?view=Mannschaft&' . $new_url_exp[1];
                    break;
                case 'AlleSpiele': return 'http://sis-handball.de/web/AlleSpiele/?view=AlleSpiele&' . $new_url_exp[1];
                    break;
                case 'Tabelle': return 'http://sis-handball.de/web/Tabelle/?view=Tabelle&' . $new_url_exp[1];
                    break;
            }
        } else {
            return false;
        }
    }

    /**
     * Autoclean the cache
     *
     * @since 1.0.8
     * @global type $wpdb
     */
    public static function clean_cache()
    {
        global $wpdb;
        $cache_date = time() - 604800; // Every week
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'sis_cache WHERE cache_time < ' . $cache_date);
    }

    /**
     * Adds last cache update time to the table
     *
     * @since 1.0.8
     * @param string $table_html
     * @param array $cache
     * @return string
     */
    private static function add_last_cache_update_time($table_html = '', $cache = [])
    {
        $returner = $table_html;
        if ($cache) {
            $returner .= '<span class="sis-cache-update">' . __('Last update:', 'sis-handball') . ' ' . wp_date('d.m.Y H:i', $cache->cache_time) . '</span>';
        }
        return $returner;
    }

    /**
     * Saves the current position
     *
     * @since 1.0.7
     * @global type $wpdb
     * @param array $data
     */
    private static function save_position_monitoring($data = [])
    {
        global $wpdb;
        if ($data) {
            $check_current_data = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'sis_monitoring WHERE team = "' . $data['team'] . '" AND gameday = "' . $data['gameday'] . '" AND url = "' . $data['url'] . '" ORDER BY id DESC LIMIT 1');
            if (!$check_current_data) {
                $data['monitoring_time'] = time();
                $wpdb->insert($wpdb->prefix . 'sis_monitoring', $data);
            }
        }
    }

    /**
     * Sets xpath of data to be received
     *
     * @since 1.0.1
     * @param type $url
     * @param type $type
     * @return type
     */
    private static function prepare_data_preprocessor($url = '', $type = '')
    {
        return Sis_Handball_Public::prepare_incoming_data($url, '//*[contains(concat(" ", normalize-space(@class), " "), " table-responsive ")]/table', $type);
    }

    /**
     * Prepare the data from SIS to return a clear array
     *
     * @since 1.0.0
     * @param type $url
     * @param type $xpath_expression
     * @param type $type
     * @return bool | array
     */
    private static function prepare_incoming_data($url = '', $xpath_expression = '', $type = '')
    {
        $doc = Sis_Handball_Public::doc_factory($url);
        if ($doc) {
            $xpath = new DOMXpath($doc);
            if ($type == 'next') {
                $elements = $xpath->query($xpath_expression)[1];
            } elseif ($type == 'team') {
                $elements = $xpath->query($xpath_expression)[0];
            } else {
                $elements = $xpath->query($xpath_expression);
            }
            return Sis_Handball_Public::incoming_elements_to_array($elements, $xpath_expression, $type);
        }
        return false;
    }

    /**
     * @since 1.0.1
     * @param type $elements
     * @param string $xpath_expression
     * @param string $type
     * @return bool | array
     */
    private static function incoming_elements_to_array($elements, $xpath_expression = '', $type = '')
    {
        $cleaner_array_keys = [];
        $cleaner_array = [];
        if (!is_null($elements)) {
            if ($type == 'next' || $type == 'team') {
                foreach ($elements->childNodes as $key1 => $element1) {
                    foreach ($element1->childNodes as $key2 => $element2) {
                        foreach ($element2->childNodes as $key3 => $element3) {
                            if ($element3->tagName == 'td') {
                                if ($key1 >= 1 && strlen($element2->nodeValue) >= 10) { // Remove table head and spacers
                                    $cleaner_array[$key2][] = $element3->nodeValue;
                                }
                            }
                        }
                    }
                }
                return Sis_Handball_Public::clean_array($cleaner_array);
            }
            foreach ($elements as $key1 => $element1) {
                foreach ($element1->childNodes as $key2 => $element2) {
                    foreach ($element2->childNodes as $key3 => $element3) {
                        foreach ($element3->childNodes as $key4 => $element4) {
                            if ($element4->tagName == 'td') {
                                if ($key2 >= 1 && strlen($element3->nodeValue) >= 10) { // Remove table head and spacers
                                    $cleaner_array[$key3][] = $element4->nodeValue;
                                }
                            }
                        }
                    }
                }
            }
            return Sis_Handball_Public::clean_array($cleaner_array);
        }
        return false;
    }

    /**
     * Prepares DOM document to get read
     *
     * @since 1.0.1
     * @param string $url
     * @return \DOMDocument
     */
    private static function doc_factory($url = '')
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        $content = utf8_decode(curl_exec($ch));
        if (Sis_Handball_Public::curl_request_validator($content)) {
            curl_close($ch);
            $doc = new DOMDocument();
            @$doc->loadHTML($content);
            return $doc;
        }
        curl_close($ch);
        return false;
    }

    /**
     * Checks if curl result is valid
     *
     * @since 1.0.11
     * @param string $curl_string
     * @return bool
     */
    private static function curl_request_validator($curl_string = '')
    {
        if (strlen($curl_string) >= 1) {
            return true;
        }
        return false;
    }

    /**
     * Clean array values
     *
     * @since 1.0.0
     * @param type $array
     * @return type
     */
    private static function clean_array($array = [])
    {
        $clean_array = [];
        foreach ($array as $key => $cleaner_array) {
            $clean_array[$key] = array_map('trim', $cleaner_array);
        }
        return $clean_array;
    }

    /**
     * Build url to call
     *
     * @since 1.0.0
     * @param string $type
     * @param string $league_id
     * @return string
     */
    private static function url_builder($type = 'team', $league_id = '')
    {
        $url_1 = 'https://sis-handball.de/default.aspx?view=';
        $url_2 = '';
        switch ($type) {
            case 'next': $url_2 = 'Mannschaft&Liga=';
                break;
            case 'team': $url_2 = 'Mannschaft&Liga=';
                break;
            case 'concat': $url_2 = 'Mannschaft&Liga=';
                break;
            case 'games': $url_2 = 'AlleSpiele&Liga=';
                break;
            case 'standings': $url_2 = 'Tabelle&Liga=';
                break;
            case 'chart': $url_2 = 'Tabelle&Liga=';
                break;
            case 'stats': $url_2 = 'Tabelle&Liga=';
                break;
            case 'club': $url_2 = 'Gesamtspielplan&Verein=';
                break;
        }
        $url_3 = $league_id;
        return $url_1 . $url_2 . $url_3;
    }

    /**
     * Build chart structure
     *
     * since 1.0.7
     * @param array $data
     * @return string
     */
    private static function chart_factory($data = [])
    {
        if ($data) {
            $data = apply_filters('sis_handball_chart_data', $data);
            $chart_data = '';
            foreach ($data['chart'] as $chart_step) {
                $chart_data .= '["' . $chart_step['gameday'] . '", ' . $chart_step['position'] . '],' . "\n";
            }
            $returner = '
                <p>' . __('Position per gameday for: ', 'sis-handball') . $data['team'] . '</p>
                <script>
                jQuery(document).ready(function() {
                    google.charts.load("current", {"packages":["corechart"]});
                    google.charts.setOnLoadCallback(drawChart);

                    function drawChart() {
                      var data = google.visualization.arrayToDataTable([
                        ["' . __('Gameday', 'sis-handball') . '", "' . __('Position', 'sis-handball') . '"],
                        ' . $chart_data . '
                      ]);

                      var options = {
                        legend: "none",
                        vAxis: {
                            title: "' . __('Position', 'sis-handball') . '",
                            direction: -1,
                            format: 0,
                            maxValue: 2,
                        },
                        hAxis: {
                            title: "' . __('Gameday', 'sis-handball') . '",
                        },
                        "width": "100%",
                        "height": 350,
                        "chartArea": {
                            "width": "75%",
                            "height": "75%",
                        },
                      };

                      var chart = new google.visualization.LineChart(document.getElementById("position_chart"));

                      chart.draw(data, options);
                    }
                });
                </script>
                <div id="position_chart"></div>
            ';
            return $returner;
        }
        return Sis_Handball_Public::error_handler('no_data');
    }

    /**
     * Build html table structure
     *
     * @since 1.0.0
     * @param array $data
     * @param array $atts
     * @return string
     */
    private static function table_factory($data = [], $atts = [])
    {
        $type = $atts['type'];
        $returner = '';
        if ($data && count($data) >= 1) {
            $data = apply_filters('sis_handball_table_data', $data, $atts);
            $hide_cols = [];
            if ($atts['hide_cols']) {
                $hide_cols = explode(',', $atts['hide_cols']);
            }
            $returner .= '<table class="sis-handball-table sis-league-id-' . $atts['league'] . ' sis-handball-type-' . $atts['type'] . Sis_Handball_Public::additional_param_class($atts) . '">';

            /* Next and past games of a single team */
            if ($type == 'team' || $type == 'games') {
                if (Sis_Handball_Public::additional_param_table_head($atts) != 'hidden') {
                    $returner .= '<thead>';
                    $returner .= '<tr>';
                    if (!in_array('1', $hide_cols)) {
                        $returner .= '<th>' . __('Date', 'sis-handball') . '</th>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $returner .= '<th>' . __('Time', 'sis-handball') . '</th>';
                    }
                    if (!in_array('3', $hide_cols)) {
                        $returner .= '<th>' . __('Home', 'sis-handball') . '</th>';
                    }
                    if (!in_array('4', $hide_cols)) {
                        $returner .= '<th>' . __('Guest', 'sis-handball') . '</th>';
                    }
                    if (!in_array('5', $hide_cols)) {
                        $returner .= '<th>' . __('Goals', 'sis-handball') . '</th>';
                    }
                    if (!in_array('6', $hide_cols)) {
                        $returner .= '<th>' . __('Points', 'sis-handball') . '</th>';
                    }
                    $returner .= '</tr>';
                    $returner .= '</thead>';
                }
                $returner .= '<tbody>';
                $key = 0;
                foreach ($data as $data_part) {
                    $key++;
                    $mark_winner_home = '';
                    $mark_winner_guest = '';
                    $mark_row = '';
                    $table_rows = 0;
                    if ($atts['marked']) {
                        if ($data_part[0] == $atts['marked'] && $data_part[3] == '2:0') {
                            $mark_winner_home = ' class="marked-winner"';
                        }
                        if ($data_part[1] == $atts['marked'] && $data_part[3] == '0:2') {
                            $mark_winner_guest = ' class="marked-winner"';
                        }
                        if ($data_part[0] == $atts['marked'] || $data_part[1] == $atts['marked']) {
                            $mark_row = ' sis-handball-marked-row';
                        }
                    }
                    if ($atts['limit']) {
                        $hidden_by_limit = count($data) - $atts['limit'];
                        $limit_class = '';
                        if ($atts['limit'] < $key) {
                            $limit_class = ' sis-limit-hidden';
                        }
                    }
                    $returner .= '<tr class="' . $limit_class . $mark_row . '">';
                    if (!in_array('1', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::date_format($data_part[4], 'D') . '. ' . Sis_Handball_Public::date_format($data_part[4]) . '</td>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::date_format($data_part[4], 'H:i') . '</td>';
                    }
                    if (!in_array('3', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td' . $mark_winner_home . '>' . Sis_Handball_Public::team_name($data_part[0], $atts) . '</td>';
                    }
                    if (!in_array('4', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td' . $mark_winner_guest . '>' . Sis_Handball_Public::team_name($data_part[1], $atts) . '</td>';
                    }
                    if (!in_array('5', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[2] . '</td>';
                    }
                    if (!in_array('6', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[3] . '</td>';
                    }
                    $returner .= '</tr>';
                    if ($atts['limit']) {
                        if ($atts['limit'] == $key && $hidden_by_limit >= 1) {
                            $returner .= Sis_Handball_Public::show_more_sentence($hidden_by_limit, $table_rows);
                        }
                    }
                }
            }

            /* Standings of a league */
            if ($type == 'standings') {
                if (Sis_Handball_Public::additional_param_table_head($atts) != 'hidden') {
                    $returner .= '<thead>';
                    $returner .= '<tr>';
                    if (!in_array('1', $hide_cols)) {
                        $returner .= '<th>' . __('No.', 'sis-handball') . '</th>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $returner .= '<th>' . __('Team', 'sis-handball') . '</th>';
                    }
                    if (!in_array('3', $hide_cols)) {
                        $returner .= '<th>' . __('Games', 'sis-handball') . '</th>';
                    }
                    if (!in_array('4', $hide_cols)) {
                        $returner .= '<th>' . __('W', 'sis-handball') . '</th>';
                    }
                    if (!in_array('5', $hide_cols)) {
                        $returner .= '<th>' . __('T', 'sis-handball') . '</th>';
                    }
                    if (!in_array('6', $hide_cols)) {
                        $returner .= '<th>' . __('L', 'sis-handball') . '</th>';
                    }
                    if (!in_array('7', $hide_cols)) {
                        $returner .= '<th>' . __('Goals', 'sis-handball') . '</th>';
                    }
                    if (!in_array('8', $hide_cols)) {
                        $returner .= '<th>' . __('D', 'sis-handball') . '</th>';
                    }
                    if (!in_array('9', $hide_cols)) {
                        $returner .= '<th>' . __('Points', 'sis-handball') . '</th>';
                    }
                    $returner .= '</tr>';
                    $returner .= '</thead>';
                }
                $key = 0;
                foreach ($data as $data_part) {
                    $key++;
                    $table_rows = 0;
                    $classes_tr = [];

                    if ($atts['limit']) {
                        $hidden_by_limit = count($data) - $atts['limit'];
                        if ($atts['limit'] < $key) {
                            $classes_tr['limit_class'] = 'sis-limit-hidden';
                        }
                    }
                    if ($atts['marked']) {
                        if ($data_part[1] == $atts['marked']) {
                            $classes_tr['marked_class'] = 'marked';
                        }
                    }

                    $class_string = '';
                    if (count($classes_tr) >= 1) {
                        $class_string = ' class="';
                        foreach ($classes_tr as $class_tr) {
                            $class_string .= $class_tr . ' ';
                        }
                        $class_string = rtrim($class_string);
                        $class_string .= '"';
                    }

                    $returner .= '<tr' . $class_string . '>';
                    if (!in_array('1', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[0] . '</td>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::team_name($data_part[1], $atts) . '</td>';
                    }
                    if (!in_array('3', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[2] . '</td>';
                    }
                    if (!in_array('4', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[3] . '</td>';
                    }
                    if (!in_array('5', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[4] . '</td>';
                    }
                    if (!in_array('6', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[5] . '</td>';
                    }
                    if (!in_array('7', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[6] . '</td>';
                    }
                    if (!in_array('8', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[7] . '</td>';
                    }
                    if (!in_array('9', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[8] . '</td>';
                    }
                    $returner .= '</tr>';
                    if ($atts['limit']) {
                        if ($atts['limit'] == $key && $hidden_by_limit >= 1) {
                            $returner .= Sis_Handball_Public::show_more_sentence($hidden_by_limit, $table_rows);
                        }
                    }
                }
            }

            /* Next games for a single team */
            if ($type == 'next') {
                if (Sis_Handball_Public::additional_param_table_head($atts) != 'hidden') {
                    $returner .= '<thead>';
                    $returner .= '<tr>';
                    if (!in_array('1', $hide_cols)) {
                        $returner .= '<th>' . __('Date', 'sis-handball') . '</th>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $returner .= '<th>' . __('Time', 'sis-handball') . '</th>';
                    }
                    if (!in_array('3', $hide_cols) && !in_array('hide-team', $hide_cols)) {
                        $returner .= '<th>' . __('Home', 'sis-handball') . '</th>';
                    }
                    if (!in_array('4', $hide_cols) && !in_array('hide-team', $hide_cols)) {
                        $returner .= '<th>' . __('Guest', 'sis-handball') . '</th>';
                    }
                    if (in_array('hide-team', $hide_cols)) {
                        $returner .= '<th>' . __('Opponent', 'sis-handball') . '</th>';
                    }
                    $returner .= '</tr>';
                    $returner .= '</thead>';
                }
                $key = 0;
                foreach ($data as $data_part) {
                    $key++;
                    $table_rows = 0;
                    if ($atts['limit']) {
                        $hidden_by_limit = count($data) - $atts['limit'];
                        $limit_class = '';
                        if ($atts['limit'] < $key) {
                            $limit_class = ' sis-limit-hidden';
                        }
                    }
                    if ($atts['marked']) {
                        $marked_class_1 = '';
                        $marked_class_2 = '';
                        if ($data_part[1] == $atts['marked']) {
                            $marked_class_1 = ' class="marked"';
                        }
                        if ($data_part[2] == $atts['marked']) {
                            $marked_class_2 = ' class="marked"';
                        }
                    }
                    $returner .= '<tr class="' . $limit_class . '">';
                    if (!in_array('1', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::date_format($data_part[0], 'D') . '. ' . Sis_Handball_Public::date_format($data_part[0]) . '</td>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::date_format($data_part[0], 'H:i') . '</td>';
                    }
                    if (!in_array('3', $hide_cols) && !in_array('hide-team', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td' . $marked_class_1 . '>' . Sis_Handball_Public::team_name($data_part[1], $atts) . '</td>';
                    }
                    if (!in_array('4', $hide_cols) && !in_array('hide-team', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td' . $marked_class_2 . '>' . Sis_Handball_Public::team_name($data_part[2], $atts) . '</td>';
                    }
                    if (in_array('hide-team', $hide_cols)) {
                        $table_rows++;
                        if ($data_part[1] == $atts['marked']) {
                            $returner .= '<td' . $marked_class_2 . '>' . Sis_Handball_Public::team_name($data_part[2], $atts) . '</td>';
                        } else {
                            $returner .= '<td' . $marked_class_1 . '>' . Sis_Handball_Public::team_name($data_part[1], $atts) . '</td>';
                        }
                    }
                    $returner .= '</tr>';
                    if ($atts['limit']) {
                        if ($atts['limit'] == $key && $hidden_by_limit >= 1) {
                            $returner .= Sis_Handball_Public::show_more_sentence($hidden_by_limit, $table_rows);
                        }
                    }
                }
            }

            /* Stats for a single team */
            if ($type == 'stats') {
                foreach ($data as $data_part) {
                    $marked = $atts['marked'];
                    if ($marked) {
                        if ($data_part[1] == $marked) {
                            if (Sis_Handball_Public::additional_param_table_head($atts) != 'hidden') {
                                $returner .= '<thead>';
                                $returner .= '<tr>';
                                if (!in_array('1', $hide_cols)) {
                                    $returner .= '<th>' . __('Position', 'sis-handball') . '</th>';
                                }
                                if (!in_array('2', $hide_cols)) {
                                    $returner .= '<th>' . __('Games made', 'sis-handball') . '</th>';
                                }
                                if (!in_array('3', $hide_cols)) {
                                    $returner .= '<th>' . __('Average goals made', 'sis-handball') . '</th>';
                                }
                                if (!in_array('4', $hide_cols)) {
                                    $returner .= '<th>' . __('Average goals got', 'sis-handball') . '</th>';
                                }
                                if (!in_array('5', $hide_cols)) {
                                    $returner .= '<th>' . __('W', 'sis-handball') . ':' . __('T', 'sis-handball') . ':' . __('L', 'sis-handball') . '</th>';
                                }
                                $returner .= '</tr>';
                                $returner .= '</thead>';
                            }
                            $games_made = $data_part[2];
                            $goals_complete = $data_part[6];
                            $goals_per_game = 0;
                            $goals_per_game_made = 0;
                            $goals_per_game_got = 0;
                            if ($games_made >= 1) {
                                $goals_per_game = explode(':', $goals_complete);
                                $goals_per_game_made = round($goals_per_game[0] / $games_made);
                                $goals_per_game_got = round($goals_per_game[1] / $games_made);
                            }
                            $returner .= '<tr>';
                            if (!in_array('1', $hide_cols)) {
                                $returner .= '<td>' . $data_part[0] . '</td>';
                            }
                            if (!in_array('2', $hide_cols)) {
                                $returner .= '<td>' . $games_made . '</td>';
                            }
                            if (!in_array('3', $hide_cols)) {
                                $returner .= '<td>' . $goals_per_game_made . '</td>';
                            }
                            if (!in_array('4', $hide_cols)) {
                                $returner .= '<td>' . $goals_per_game_got . '</td>';
                            }
                            if (!in_array('5', $hide_cols)) {
                                $returner .= '<td>' . $data_part[3] . ':' . $data_part[4] . ':' . $data_part[5] . '</td>';
                            }
                            $returner .= '</tr>';
                        }
                    }
                }
            }

            /* All games of a club */
            if ($type == 'club') {
                if (Sis_Handball_Public::additional_param_table_head($atts) != 'hidden') {
                    $returner .= '<thead>';
                    $returner .= '<tr>';
                    if (!in_array('1', $hide_cols)) {
                        $returner .= '<th>' . __('Date', 'sis-handball') . '</th>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $returner .= '<th>' . __('Home', 'sis-handball') . '</th>';
                    }
                    if (!in_array('3', $hide_cols)) {
                        $returner .= '<th>' . __('Guest', 'sis-handball') . '</th>';
                    }
                    if (!in_array('4', $hide_cols)) {
                        $returner .= '<th>' . __('Location', 'sis-handball') . '</th>';
                    }
                    $returner .= '</tr>';
                    $returner .= '</thead>';
                }
                $key = 0;
                foreach ($data as $data_part) {
                    $key++;
                    $table_rows = 0;
                    if ($atts['limit']) {
                        $hidden_by_limit = count($data) - $atts['limit'];
                        $limit_class = '';
                        if ($atts['limit'] < $key) {
                            $limit_class = ' sis-limit-hidden';
                        }
                    }
                    $returner .= '<tr class="' . $limit_class . '">';
                    if (!in_array('1', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[1] . '</td>';
                    }
                    if (!in_array('2', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::team_name($data_part[3], $atts) . '</td>';
                    }
                    if (!in_array('3', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . Sis_Handball_Public::team_name($data_part[4], $atts) . '</td>';
                    }
                    if (!in_array('4', $hide_cols)) {
                        $table_rows++;
                        $returner .= '<td>' . $data_part[5] . '</td>';
                    }
                    $returner .= '</tr>';
                    if ($atts['limit']) {
                        if ($atts['limit'] == $key && $hidden_by_limit >= 1) {
                            $returner .= Sis_Handball_Public::show_more_sentence($hidden_by_limit, $table_rows);
                        }
                    }
                }
            }

            $returner .= '
                    </tbody>
                </table>
            ';
            return $returner;
        }
        return Sis_Handball_Public::error_handler('no_data');
    }

    /**
     * Builds concatenation html output
     *
     * @since 1.0.16
     * @param type $data
     * @param type $atts
     * @return string
     */
    private static function concatenation_factory($data = [], $atts = [])
    {
        $type = $atts['type'];
        $returner = '';
        if ($data && count($data) >= 1) {
            $data = apply_filters('sis_handball_concatenation_data', $data, $atts);
            if ($type == 'next') {
                $returner .= '
                    <table class="sis-handball-table sis-handball-next-games-overview sis-league-id-' . $atts['league'] . ' sis-handball-type-' . $atts['type'] . Sis_Handball_Public::additional_param_class($atts) . '">
                        <thead>
                            <tr>
                                <th>' . __('Date', 'sis-handball') . '</th>
                                <th>' . __('Time', 'sis-handball') . '</th>
                                <th>' . __('Home', 'sis-handball') . '</th>
                                <th>' . __('Guest', 'sis-handball') . '</th>
                            </tr>
                        </thead>
                        <tbody>
                ';
                foreach ($data as $single_next_game) {
                    if ($single_next_game) {
                        $returner .= '
                            <tr>
                                <td>' . Sis_Handball_Public::date_format($single_next_game[0], 'D') . '. ' . Sis_Handball_Public::date_format($single_next_game[0]) . '</td>
                                <td>' . Sis_Handball_Public::date_format($single_next_game[0], 'H:i') . '</td>
                                <td>' . Sis_Handball_Public::team_name($single_next_game[1], $atts) . '</td>
                                <td>' . Sis_Handball_Public::team_name($single_next_game[2], $atts) . '</td>
                            </tr>
                        ';
                    }
                }
                $returner .= '
                        </tbody>
                    </table>
                ';
            }
            return $returner;
        }
        return Sis_Handball_Public::error_handler('no_data');
    }

    /**
     * Returns error messages
     *
     * @since 1.0.6
     * @param string $error
     * @return string
     */
    private static function error_handler($error = '')
    {
        $output = '';
        $text = '';
        $hide_errors = get_option('sis-handball-hide-errors');
        if ($hide_errors != 1) {
            switch ($error) {
                case 'no_data':
                    $text = Sis_Handball_Public::internal_translate('sis-handball-text-error-no-data', __('Error: No data received!', 'sis-handball'));
                    break;
                default:
                    $text = Sis_Handball_Public::internal_translate('sis-handball-text-error-default', __('Error: An error occured!', 'sis-handball'));
            }
            $output .= '<div class="sis-error">' . $text . '</div>';
        }
        return $output;
    }

    /**
     * Format dates
     *
     * @since 1.0.40
     * @param type $date
     * @param type $output_format
     * @param type $format
     * @return string|bool
     */
    private static function date_format($date = '', $output_format = 'd.m.y', $format = 'd.m.y H:i')
    {
        $date = str_replace(' ', '', $date);
        $datetime = DateTime::createFromFormat($format, $date);
        if ($datetime) {
            $utc_timezone = new \DateTimeZone('UTC');
            $utc_date = new \DateTime($datetime->format('Y-m-d H:i:s'), $utc_timezone);
            $timestamp = $utc_date->getTimestamp();
            return date_i18n($output_format, $timestamp, true);
        }
        return false;
    }

    /**
     * Loads data from database cache
     *
     * @since 1.0.0
     * @global type $wpdb
     * @param string $url
     * @param string $type
     * @return type
     */
    private static function load_from_cache($url = '', $type = '')
    {
        global $wpdb;
        $cache_minus = Sis_Handball_Public::get_cache_timeout();
        $cache_timer = time() - $cache_minus;
        $cache_result = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'sis_cache WHERE url = "' . $url . '" AND type = "' . $type . '" AND cache_time > ' . $cache_timer . ' ORDER BY id DESC');
        return $cache_result;
    }

    /**
     * Returns custom cache timout
     *
     * @since 1.0.10
     * @return int
     */
    private static function get_cache_timeout()
    {
        $cache_minus = 28800;
        switch (get_option('sis-handball-cache-time')) {
            case '4h': $cache_minus = 14400;
                break;
            case '8h': $cache_minus = 28800;
                break;
            case '12h': $cache_minus = 43200;
                break;
            case '1d': $cache_minus = 86400;
                break;
            case '1w': $cache_minus = 604800;
                break;
        }
        return $cache_minus;
    }

    /**
     * Loads data from saved snapshot
     *
     * @since 1.0.5
     * @global type $wpdb
     * @param string $snapshot_id
     * @return type
     */
    private static function load_from_snapshot($snapshot_code = '')
    {
        global $wpdb;
        $snapshot_result = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'sis_snapshots WHERE snapshot_code = "' . $snapshot_code . '" LIMIT 1');
        if ($snapshot_result) {
            return unserialize($snapshot_result->snapshot);
        }
        return $snapshot_result;
    }

    /**
     * Writes data into the cache
     *
     * @since 1.0.0
     * @global type $wpdb
     * @param array $data
     * @param string $url
     * @param string $type
     */
    private static function write_to_cache($data = [], $url = '', $type = '')
    {
        if ($data) {
            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'sis_cache', ['cache_time' => time(), 'cache' => serialize($data), 'url' => $url, 'type' => $type]);
        }
    }

    /**
     * Build concatenation output
     *
     * @since 1.0.16
     * @param type $concatenation_id
     * @return string
     */
    private static function get_concatenation_data($concatenation_id = 0)
    {
        $conditions = Sis_Handball_Public::get_concatenation_conditions($concatenation_id);
        $data_helper_array = [];
        foreach ($conditions as $condition) {
            $condition_data = unserialize($condition->data);
            $data = Sis_Handball_Public::shortcode_sis_handball($condition_data, '', 'sishandball', true);
            $data_helper_array[] = $data[0];
        }
        return Sis_Handball_Public::concatenation_factory($data_helper_array, ['type' => 'next']);
    }

    /**
     * Get conditions of concatenation by id
     *
     * @since 1.0.16
     * @global type $wpdb
     * @param type $concatenation_id
     * @return type
     */
    private static function get_concatenation_conditions($concatenation_id = 0)
    {
        global $wpdb;
        $conditions = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sis_concatenation_conditions WHERE concatenation_id = ' . $concatenation_id);
        return $conditions;
    }

    /**
     * Get additional parameter from shortcode attributes
     *
     * @since 1.0.33
     * @param type $atts
     * @return type
     */
    private static function additional_params($atts = [])
    {
        $additional_params = $atts['additional_params'];
        if ($additional_params) {
            $additional_params = explode("'|", $additional_params);
            foreach ($additional_params as $additional_param) {
                $additional_param = explode(':', $additional_param);
                $additional_params[$additional_param[0]] = str_replace("'", '', $additional_param[1]);
            }
        }
        return $additional_params;
    }

    /**
     * Check class additional parameter
     *
     * @since 1.0.33
     * @param type $atts
     * @return bool
     */
    private static function additional_param_class($atts = [])
    {
        $additional_params = Sis_Handball_Public::additional_params($atts);
        if (is_array($additional_params)) {
            if (array_key_exists('class', $additional_params)) {
                return ' ' . $additional_params['class'];
            }
        }
        return false;
    }

    /**
     * Check table_head additional parameter
     *
     * @since 1.0.33
     * @param type $atts
     * @return bool|string
     */
    private static function additional_param_table_head($atts = [])
    {
        $additional_params = Sis_Handball_Public::additional_params($atts);
        if (is_array($additional_params)) {
            if (array_key_exists('table_head', $additional_params)) {
                if ($additional_params['table_head'] == 'hidden') {
                    return 'hidden';
                }
            }
        }
        return false;
    }

    /**
     * Check cache additional parameter
     *
     * @since 1.0.33
     * @param type $atts
     * @return bool|string
     */
    private static function additional_param_cache($atts = [])
    {
        $additional_params = Sis_Handball_Public::additional_params($atts);
        if (is_array($additional_params)) {
            if (array_key_exists('cache', $additional_params)) {
                if ($additional_params['cache'] == 'no-cache') {
                    return 'no-cache';
                }
            }
        }
        return false;
    }

    /**
     * Check ignore team replacement additional parameter
     *
     * @since 1.0.34
     * @param type $atts
     * @return bool
     */
    private static function additional_param_ignore_team_replacement($atts = [])
    {
        $additional_params = Sis_Handball_Public::additional_params($atts);
        if (is_array($additional_params)) {
            if (array_key_exists('ignore-team-replacement', $additional_params)) {
                if ($additional_params['ignore-team-replacement'] == '1') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check ignore lazy loading additional parameter
     *
     * @since 1.0.40
     * @param type $atts
     * @return bool
     */
    private static function additional_param_ignore_lazy_loading($atts = [])
    {
        $additional_params = Sis_Handball_Public::additional_params($atts);
        if (is_array($additional_params)) {
            if (array_key_exists('ignore-lazy-loading', $additional_params)) {
                if ($additional_params['ignore-lazy-loading'] == '1') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Manage internal translations
     *
     * @since 1.0.34
     * @param type $translation_key
     * @param type $original_string
     * @return type
     */
    private static function internal_translate($translation_key = '', $original_string = '')
    {
        if (get_option($translation_key)) {
            return get_option($translation_key);
        }
        return $original_string;
    }

    /**
     * Build the show more link after limited tables
     *
     * @since 1.0.34
     * @param int $limit
     * @param int $more_results
     */
    private static function show_more_sentence($limit = 0, $more_results = 0)
    {
        $returner = '';
        $show_more_sentence = Sis_Handball_Public::internal_translate('sis-handball-text-show-more-plural', __('more elements to show.', 'sis-handball'));
        if ($limit == 1) {
            $show_more_sentence = Sis_Handball_Public::internal_translate('sis-handball-text-show-more-singular', __('more element to show.', 'sis-handball'));
        }
        $returner .= '
            <tr class="show-more sis-limit-show-more">
                <td colspan="' . $more_results . '">' . $limit . ' ' . $show_more_sentence . '</td>
            </tr>
        ';
        return $returner;
    }

    /**
     * Render team names
     * - Replace team names with custom overrides
     *
     * @since 1.0.34
     * @global type $wpdb
     * @param type $team_name
     * @param type $atts
     * @return type
     */
    private static function team_name($team_name = '', $atts = [])
    {
        global $wpdb;
        $additional_params_ignore_team_replacement = Sis_Handball_Public::additional_param_ignore_team_replacement($atts);
        if (!$additional_params_ignore_team_replacement) {
            $replace_datasets = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sis_string_replace ORDER BY id ASC');
            foreach ($replace_datasets as $replacement) {
                if ($replacement->source_string == $team_name) {
                    $team_name = $replacement->replace_string;
                }
            }
        }
        return $team_name;
    }
}
