<?php
/**
 * @package org.openpsa.directmarketing
 * @author The Midgard Project, http://www.midgard-project.net
 * @copyright The Midgard Project, http://www.midgard-project.net
 * @license http://www.gnu.net/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;

/**
 * directmarketing edit/delete campaign handler
 *
 * @package org.openpsa.directmarketing
 */
class org_openpsa_directmarketing_handler_campaign_admin extends midcom_baseclasses_components_handler
{
    use org_openpsa_directmarketing_handler;

    /**
     * Displays a campaign edit view.
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_edit($handler_id, array $args, array &$data)
    {
        $campaign = $this->load_campaign($args[0]);
        $campaign->require_do('midgard:update');

        $dm = datamanager::from_schemadb($this->_config->get('schemadb_campaign'));
        $dm->set_storage($campaign);

        midcom::get()->head->set_pagetitle($this->_l10n->get('edit campaign'));

        $workflow = $this->get_workflow('datamanager', ['controller' => $dm->get_controller()]);
        return $workflow->run();
    }

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_delete($handler_id, array $args, array &$data)
    {
        $campaign = $this->load_campaign($args[0]);
        $workflow = $this->get_workflow('delete', ['object' => $campaign]);
        return $workflow->run();
    }
}
