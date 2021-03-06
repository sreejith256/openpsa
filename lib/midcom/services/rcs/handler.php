<?php
/**
 * @package midcom.services.rcs
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 */

/**
 * @package midcom.services.rcs
 */
abstract class midcom_services_rcs_handler extends midcom_baseclasses_components_handler
{
    /**
     * RCS backend
     *
     * @var midcom_services_rcs_backend
     */
    private $backend;

    /**
     * Pointer to midgard object
     *
     * @var midcom_core_dbaobject
     */
    protected $object;

    protected $style_prefix = '';

    protected $url_prefix = '';

    abstract protected function get_object_url();

    abstract protected function handler_callback($handler_id);

    abstract protected function get_breadcrumbs();

    protected function resolve_object_title()
    {
        return midcom_helper_reflector::get($this->object)->get_object_label($this->object);
    }

    /**
     * Load the object and the rcs backend
     *
     * @param string $guid
     */
    private function load_object($guid)
    {
        $this->object = midcom::get()->dbfactory->get_object_by_guid($guid);

        if (   !midcom::get()->config->get('midcom_services_rcs_enable')
            || !$this->object->_use_rcs) {
            throw new midcom_error_notfound("Revision control not supported for " . get_class($this->object) . ".");
        }

        $this->backend = midcom::get()->rcs->load_handler($this->object);
    }

    /**
     * Prepare version control toolbar
     */
    private function rcs_toolbar($current = null, $diff_view = false)
    {
        $this->add_stylesheet(MIDCOM_STATIC_URL . "/midcom.services.rcs/rcs.css");
        $prefix = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX) . $this->url_prefix;

        $this->_request_data['rcs_toolbar'] = new midcom_helper_toolbar();

        $last = $this->build_rcs_toolbar($this->_request_data['rcs_toolbar'], $current, $diff_view);

        // RCS functional toolbar
        $this->_request_data['rcs_toolbar_2'] = new midcom_helper_toolbar();
        $buttons = [
            [
                MIDCOM_TOOLBAR_URL => "{$prefix}{$this->object->guid}/",
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('show history'),
                MIDCOM_TOOLBAR_GLYPHICON => 'history',
            ]
        ];

        if (!empty($current)) {
            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "{$prefix}restore/{$this->object->guid}/{$current}/",
                MIDCOM_TOOLBAR_LABEL => sprintf($this->_l10n->get('restore version %s'), $current),
                MIDCOM_TOOLBAR_GLYPHICON => 'recycle',
                MIDCOM_TOOLBAR_ENABLED => ($current !== $last),
            ];
        }
        $this->_request_data['rcs_toolbar_2']->add_items($buttons);
    }

    private function build_rcs_toolbar(midcom_helper_toolbar $toolbar, $current, $diff_view)
    {
        $keys = array_keys($this->backend->list_history());
        if (!isset($keys[0])) {
            return;
        }
        $prefix = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX) . $this->url_prefix;
        $first = end($keys);
        $last = $keys[0];

        $buttons = [[
            MIDCOM_TOOLBAR_URL => "{$prefix}preview/{$this->object->guid}/{$first}",
            MIDCOM_TOOLBAR_LABEL => $first,
            MIDCOM_TOOLBAR_GLYPHICON => 'fast-backward',
            MIDCOM_TOOLBAR_ENABLED => ($current !== $first || $diff_view),
        ]];

        if (!empty($current)) {
            $previous = $this->backend->get_prev_version($current);
            if (!$previous) {
                $previous = $first;
            }

            $next = $this->backend->get_next_version($current);
            if (!$next) {
                $next = $last;
            }

            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "{$prefix}preview/{$this->object->guid}/{$previous}",
                MIDCOM_TOOLBAR_LABEL => $previous,
                MIDCOM_TOOLBAR_GLYPHICON => 'backward',
                MIDCOM_TOOLBAR_ENABLED => ($current !== $first || $diff_view),
            ];

            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "{$prefix}diff/{$this->object->guid}/{$current}/{$previous}/",
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('show differences'),
                MIDCOM_TOOLBAR_GLYPHICON => 'step-backward',
                MIDCOM_TOOLBAR_ENABLED => ($current !== $first),
            ];

            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "{$prefix}preview/{$current}/{$current}/",
                MIDCOM_TOOLBAR_LABEL => sprintf($this->_l10n->get('version %s'), $current),
                MIDCOM_TOOLBAR_GLYPHICON => 'file-o',
                MIDCOM_TOOLBAR_ENABLED => false,
            ];

            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "{$prefix}diff/{$this->object->guid}/{$current}/{$next}/",
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('show differences'),
                MIDCOM_TOOLBAR_GLYPHICON => 'step-forward',
                MIDCOM_TOOLBAR_ENABLED => ($current !== $last),
            ];

            $buttons[] = [
                MIDCOM_TOOLBAR_URL => "{$prefix}preview/{$this->object->guid}/{$next}",
                MIDCOM_TOOLBAR_LABEL => $next,
                MIDCOM_TOOLBAR_GLYPHICON => 'forward',
                MIDCOM_TOOLBAR_ENABLED => ($current !== $last || $diff_view),
            ];
        }

        $buttons[] = [
            MIDCOM_TOOLBAR_URL => "{$prefix}preview/{$this->object->guid}/{$last}",
            MIDCOM_TOOLBAR_LABEL => $last,
            MIDCOM_TOOLBAR_GLYPHICON => 'fast-forward',
            MIDCOM_TOOLBAR_ENABLED => ($current !== $last || $diff_view),
        ];

        $toolbar->add_items($buttons);
        return $last;
    }

    private function prepare_request_data($view_title)
    {
        $breadcrumbs = $this->get_breadcrumbs();
        if (!empty($breadcrumbs)) {
            foreach ($breadcrumbs as $item) {
                $this->add_breadcrumb($item[MIDCOM_NAV_URL], $item[MIDCOM_NAV_NAME]);
            }
        }
        $this->add_breadcrumb($this->url_prefix . "{$this->object->guid}/", $this->_l10n->get('show history'));

        if (!empty($this->_request_data['latest_revision'])) {
            $this->add_breadcrumb(
                $this->url_prefix . "preview/{$this->object->guid}/{$this->_request_data['latest_revision']}/",
                sprintf($this->_l10n->get('version %s'), $this->_request_data['latest_revision'])
            );
        }
        if (!empty($this->_request_data['compare_revision'])) {
            $this->add_breadcrumb(
                $this->url_prefix . "diff/{$this->object->guid}/{$this->_request_data['compare_revision']}/{$this->_request_data['latest_revision']}/",
                sprintf($this->_l10n->get('differences between %s and %s'), $this->_request_data['compare_revision'], $this->_request_data['latest_revision'])
            );
        }
        $this->_request_data['handler'] = $this;
        $this->_request_data['view_title'] = $view_title;
        midcom::get()->head->set_pagetitle($view_title);
    }

    public function translate($string)
    {
        $translated = $string;
        $component = midcom::get()->dbclassloader->get_component_for_class($this->object->__midcom_class_name__);
        if (midcom::get()->componentloader->is_installed($component)) {
            $translated = midcom::get()->i18n->get_l10n($component)->get($string);
        }
        if ($translated === $string) {
            $translated = $this->_l10n->get($string);
        }
        if ($translated === $string) {
            $translated = $this->_l10n_midcom->get($string);
        }
        return $translated;
    }

    /**
     * Show the changes done to the object
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_history($handler_id, array $args, array &$data)
    {
        // Check if the comparison request is valid
        if (    !empty($_GET['first']) && !empty($_GET['last'])
            && $_GET['first']  !== $_GET['last']) {
            return new midcom_response_relocate($this->url_prefix . "diff/{$args[0]}/{$_GET['first']}/{$_GET['last']}/");
        }

        $this->load_object($args[0]);
        $view_title = sprintf($this->_l10n->get('revision history of %s'), $this->resolve_object_title());

        $this->rcs_toolbar();
        $this->prepare_request_data($view_title);
        return $this->handler_callback($handler_id);
    }

    /**
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array &$data The local request data.
     */
    public function _show_history($handler_id, array &$data)
    {
        $data['history'] = $this->backend->list_history();
        $data['guid'] = $this->object->guid;
        midcom_show_style($this->style_prefix . 'history');
    }

    /**
     * Show a diff between two versions
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_diff($handler_id, array $args, array &$data)
    {
        $this->load_object($args[0]);

        if (   !$this->backend->version_exists($args[1])
            || !$this->backend->version_exists($args[2])) {
            throw new midcom_error_notfound("One of the revisions {$args[1]} or {$args[2]} does not exist.");
        }

        $data['diff'] = $this->backend->get_diff($args[1], $args[2]);
        $data['comment'] = $this->backend->get_comment($args[2]);

        // Set the version numbers
        $data['compare_revision'] = $args[1];
        $data['latest_revision'] = $args[2];
        $data['guid'] = $args[0];

        $view_title = sprintf($this->_l10n->get('changes done in revision %s to %s'), $data['latest_revision'], $this->resolve_object_title());

        // Load the toolbars
        $this->rcs_toolbar($args[2], true);
        $this->prepare_request_data($view_title);
        return $this->handler_callback($handler_id);
    }

    /**
     * Show the differences between the versions
     */
    public function _show_diff()
    {
        midcom_show_style($this->style_prefix . 'diff');
    }

    /**
     * View previews
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_preview($handler_id, array $args, array &$data)
    {
        $revision = $args[1];
        $data['latest_revision'] = $revision;
        $data['guid'] = $args[0];

        $this->load_object($args[0]);
        $data['preview'] = $this->backend->get_revision($revision);

        $this->_view_toolbar->hide_item($this->url_prefix . "preview/{$this->object->guid}/{$revision}/");

        $view_title = sprintf($this->_l10n->get('viewing version %s of %s'), $revision, $this->resolve_object_title());
        // Load the toolbars
        $this->rcs_toolbar($revision);
        $this->prepare_request_data($view_title);
        return $this->handler_callback($handler_id);
    }

    public function _show_preview()
    {
        midcom_show_style($this->style_prefix . 'preview');
    }

    /**
     * Restore to diff
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_restore($handler_id, array $args, array &$data)
    {
        $this->load_object($args[0]);

        $this->object->require_do('midgard:update');
        // TODO: set another privilege for restoring?

        if (   $this->backend->version_exists($args[1])
            && $this->backend->restore_to_revision($args[1])) {
            midcom::get()->uimessages->add($this->_l10n->get('midcom.admin.rcs'), sprintf($this->_l10n->get('restore to version %s successful'), $args[1]));
            return new midcom_response_relocate($this->get_object_url());
        }
        throw new midcom_error(sprintf($this->_l10n->get('restore to version %s failed, reason %s'), $args[1], midcom_connection::get_error_string()));
    }
}
