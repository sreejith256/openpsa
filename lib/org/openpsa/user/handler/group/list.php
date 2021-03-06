<?php
/**
 * @package org.openpsa.user
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * Group listing class for user management
 *
 * @package org.openpsa.user
 */
class org_openpsa_user_handler_group_list extends midcom_baseclasses_components_handler
{
    /**
     * Handle the group listing (used in dynamic load)
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_list($handler_id, array $args, array &$data)
    {
        midcom::get()->auth->require_user_do('org.openpsa.user:access', null, org_openpsa_user_interface::class);

        $tree = new org_openpsa_widgets_tree(midcom_db_group::class, 'owner');
        $tree->title_fields = ['official', 'name'];
        $tree->link_callback = [__CLASS__, 'render_link'];
        $data['tree'] = $tree;

        return $this->show('group-list');
    }

    public static function render_link($guid)
    {
        $prefix = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX);

        return $prefix . 'group/' . $guid . '/';
    }
}
