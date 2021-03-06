<?php
/**
 * @package org.openpsa.contacts
 * @author Nemein Oy http://www.nemein.com/
 * @copyright Nemein Oy http://www.nemein.com/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use midcom\datamanager\controller;
use midcom\datamanager\datamanager;

/**
 * org.openpsa.contacts group handler and viewer class.
 *
 * @package org.openpsa.contacts
 */
class org_openpsa_contacts_handler_group_edit extends midcom_baseclasses_components_handler
{
    /**
     * What type of group are we dealing with, organization or group?
     *
     * @var string
     */
    private $_type;

    /**
     * The group we're working on
     *
     * @var org_openpsa_contacts_group_dba
     */
    private $_group;

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_edit($handler_id, array $args, array &$data)
    {
        $this->_group = new org_openpsa_contacts_group_dba($args[0]);
        $this->_group->require_do('midgard:update');

        if ($this->_group->orgOpenpsaObtype < org_openpsa_contacts_group_dba::MYCONTACTS) {
            $this->_type = 'group';
        } else {
            $this->_type = 'organization';
        }

        $dm = datamanager::from_schemadb($this->_config->get('schemadb_group'))
            ->set_storage($this->_group, $this->_type);

        midcom::get()->head->set_pagetitle(sprintf($this->_l10n_midcom->get('edit %s'), $this->_l10n->get($this->_type)));

        $workflow = $this->get_workflow('datamanager', [
            'controller' => $dm->get_controller(),
            'save_callback' => [$this, 'save_callback']
        ]);
        return $workflow->run();
    }

    public function save_callback(controller $controller)
    {
        // Index the organization
        $indexer = new org_openpsa_contacts_midcom_indexer($this->_topic);
        $indexer->index($controller->get_datamanager());
        $this->router->generate('group_view', ['guid' => $this->_group->guid]);
    }
}
