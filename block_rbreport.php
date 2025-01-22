<?php
// This file is part of the block_rbreport plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

use block_rbreport\constants;
use core_reportbuilder\table\custom_report_table_view;
use core_reportbuilder\table\custom_report_table_view_filterset;
use core_table\local\filter\integer_filter;

/**
 * Custom report block.
 *
 * @package    block_rbreport
 * @author     Marina Glancy
 * @copyright  2021 Moodle Pty Ltd <support@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_rbreport extends block_base {

    /** @var stdClass $content */
    public $content = null;

    /** @var \core_reportbuilder\local\report\base */
    protected $corereport = false;

    /** @var tool_reportbuilder\report_base */
    protected $toolreport = false;

    /** @var string */
    protected $statusmessage = '';

    /**
     * Initializes class member variables.
     */
    public function init() {
        // Needed by Moodle to differentiate between blocks.
        $this->title = get_string('pluginname', 'block_rbreport');
    }

    /**
     * Returns the block contents.
     *
     * @uses \tool_tenant\local\block_rbreport::display_report()
     *
     * @return stdClass The block contents.
     */
    public function get_content() {

        if ($this->content !== null) {
            return $this->content;
        }

        if (empty($this->instance)) {
            $this->content = '';
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';

        if ($report = $this->get_core_report()) {
            $report->set_default_per_page(((int)$this->config->pagesize) ?: $report->get_default_per_page());

            // Add custom attributes to force cards/table view depending on settings.
            $configlayout = $this->config->layout ?? '';
            if ($configlayout === constants::LAYOUT_CARDS) {
                $report->add_attributes(['data-force-card' => '']);
            }
            if ($configlayout === constants::LAYOUT_TABLE) {
                $report->add_attributes(['data-force-table' => '']);
            }
            if ($configlayout === constants::LAYOUT_CHART) {
                $report->add_attributes(['data-force-chart' => '']);
                $this->content->text .= $this->chart_html();
            } else {
                $outputpage = new \core_reportbuilder\output\custom_report($report->get_report_persistent(), false);
                $output = $this->page->get_renderer('core_reportbuilder');
                $export = $outputpage->export_for_template($output);
                $outputhtml = $output->render_from_template('core_reportbuilder/report', $export);
                $this->content->text .= html_writer::div($outputhtml);

                $fullreporturl = new moodle_url('/reportbuilder/view.php', ['id' => $report->get_report_persistent()->get('id')]);
                $this->content->footer = html_writer::link($fullreporturl, get_string('gotofullreport', 'block_rbreport'));
            }
        } else if ($report = $this->get_tool_report()) {
            [$text, $footer] = component_class_callback(\tool_tenant\local\block_rbreport::class,
                'display_report', [$report, $this->page], ['', '']);
            $configlayout = $this->config->layout ?? '';
            $layoutclass = !empty($configlayout) ? 'rblayout rblayout-' . $this->config->layout : '';
            $this->content->text .= html_writer::div($text, $layoutclass);
            $this->content->footer = $footer;
        } else {
            $this->content->text .= $this->user_can_edit() && $this->page->user_is_editing() ? $this->statusmessage : '';
        }

        return $this->content;
    }

    /**
     * Defines configuration data.
     *
     * The function is called immediatly after init().
     */
    public function specialization() {

        // Load user defined title and make sure it's never empty.
        if (!empty($this->config->title)) {
            $this->title = $this->config->title;
        } else if ($report = $this->get_core_report()) {
            $this->title = $report->get_report_persistent()->get_formatted_name();
        } else if ($report = $this->get_tool_report()) {
            $this->title = format_string($report->get_reportname());
        } else {
            $this->title = get_string('pluginname', 'block_rbreport');
        }

        if ((!empty($this->config->corereport) && !$this->get_core_report()) ||
                (!empty($this->config->report) && !$this->get_tool_report())) {
            $this->statusmessage = html_writer::div(get_string('errormessage', 'block_rbreport'), 'alert alert-danger');
        } else {
            $this->statusmessage = html_writer::div(get_string('reportnotsetmessage', 'block_rbreport'));
        }
    }

    /**
     * Sets the applicable formats for the block.
     *
     * @return string[] Array of pages and permissions.
     */
    public function applicable_formats() {
        return ['all' => true];
    }

    /**
     * Allow multiple instances
     * @return bool
     */
    public function instance_allow_multiple() {
        return true;
    }

    /**
     * Return the plugin config settings for external functions
     *
     * @return stdClass
     */
    public function get_config_for_external() {
        $instanceconfigs = !empty($this->config) ? $this->config : new stdClass();

        return (object) [
            'instance' => $instanceconfigs,
            'plugin' => new stdClass(),
        ];
    }

    /**
     * Get current report
     *
     * @uses \tool_tenant\local\block_rbreport::get_converted_report_id()
     *
     * @return \core_reportbuilder\local\report\base|null
     */
    protected function get_core_report($key = 0): ?\core_reportbuilder\local\report\base {
        $reportid = $this->config->corereport[$key] ??
            component_class_callback(\tool_tenant\local\block_rbreport::class,
                'get_converted_report_id', [$this->config], 0);
        if ($reportid) {
            try {
                $report = \core_reportbuilder\manager::get_report_from_id($reportid);
                if (\core_reportbuilder\permission::can_view_report($report->get_report_persistent())) {
                    return $report;
                }
            } catch (moodle_exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get current report (tool_reportbuilder)
     *
     * @uses \tool_tenant\local\block_rbreport::fetch_report()
     *
     * @return tool_reportbuilder\report_base|null
     */
    protected function get_tool_report() {
        if ($this->toolreport === false) {
            $this->toolreport = component_class_callback(\tool_tenant\local\block_rbreport::class,
                'fetch_report',
                [$this->config],
                null);
        }
        return $this->toolreport;
    }

    protected function chart_html() {
        global $OUTPUT;

        switch ($this->config->charttype) {
            case constants::CHARTTYPE_BAR:
                $chart = new core\chart_bar();
                break;
            case constants::CHARTTYPE_BAR_STACKED:
                $chart = new core\chart_bar();
                $chart->set_stacked(true);
                break;
            case constants::CHARTTYPE_BAR_HORIZONTAL:
                $chart = new core\chart_bar();
                $chart->set_horizontal(true);
                break;
            case constants::CHARTTYPE_LINE:
                $chart = new core\chart_line();
                break;
            case constants::CHARTTYPE_PIE:
                $chart = new core\chart_pie();
                break;
            case constants::CHARTTYPE_DOUGHNUT:
                $chart = new core\chart_pie();
                $chart->set_doughnut(true);
                break;
            default:
                $chart = new core\chart_bar();
        }

        $allseries = [];
        $labels = [];
        $headers = [];
        foreach ($this->config->corereport as $key => $id) {
            $report = $this->get_core_report($key);
            // We store the pagesize within the table filterset so that it's available between AJAX requests.
            $filterset = new custom_report_table_view_filterset();
            $filterset->add_filter(new integer_filter('pagesize', null, [(int)$this->config->pagesize]));

            $table = custom_report_table_view::create($report->get_report_persistent()->get('id'));
            $table->set_filterset($filterset);
            $table->pagesize = 0;
            $table->setup();
            $table->query_db(0);

            $columns = array_keys($report->get_active_columns_by_alias());
            $series = [];
            foreach ($table->rawdata as $r) {
                $arrayr = (array)$r;
                $index = reset($arrayr);
                $formattedrow = $table->format_row($r);
                $c0 = $formattedrow[$columns[0]];
                $c1 = floatval(str_replace(',', '.', $formattedrow[$columns[1]]));
                if ($this->config->cumulative && $index > 0) {
                    $series[$index] = end($series) + $c1;
                } else {
                    $series[$index] = $c1;
                }
                if (!isset($labels[$index])) {
                    $labels[$index] = $c0;
                }
                if ($key > 0) {
                    if (!isset($allseries[$key-1][$index])) {
                        $allseries[$key-1][$index] = 0;
                    }
                }
            }
            $headers[] = $table->headers[1];
            $allseries[$key] = $series;
        }
        foreach ($labels as $lkey => $l) {
            foreach ($allseries as $askey => $s) {
                if (!isset($s[$lkey])) {
                    $allseries[$askey][$lkey] = 0;
                }
            }
        }
        foreach ($allseries as $key => $s) {
            ksort($s);
            $cs = new core\chart_series($headers[$key], array_values($s));
            $chart->add_series($cs);
        }
        ksort($labels);

        $chart->set_labels(array_values($labels));
        return '<div class="container-fluid">' .
               $OUTPUT->render_chart($chart) .
               '</div>';
    }

}
