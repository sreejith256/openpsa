<?php
/**
 * @package org.openpsa.user
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use Doctrine\ORM\Query\Expr\Join;
use midcom\grid\provider\client;
use midcom\grid\provider;

/**
 * Request class for user management
 *
 * @package org.openpsa.user
 */
class org_openpsa_user_handler_list extends midcom_baseclasses_components_handler
implements client
{
    /**
     * The grid provider
     *
     * @var provider
     */
    private $_provider;

    /**
     * The group we're working on, if any
     *
     * @var org_openpsa_contacts_group_dba
     */
    private $_group;

    public function _on_initialize()
    {
        $this->_provider = new provider($this);
    }

    /**
     * Handler for listing users
     *
     * @param string $handler_id Name of the used handler
     * @param array $args Array containing the variable arguments passed to the handler
     * @param array &$data Data passed to the show method
     */
    public function _handler_list($handler_id, array $args, array &$data)
    {
        $auth = midcom::get()->auth;
        if (!$auth->can_user_do('org.openpsa.user:access', null, org_openpsa_user_interface::class)) {
            $person = $auth->user->get_storage();
            return new midcom_response_relocate($this->router->generate('user_view', ['guid' => $person->guid]));
        }

        $data['provider_url'] = $this->router->generate('user_list_json');
        $grid_id = 'org_openpsa_user_grid';
        if (count($args) == 1) {
            $grid_id = 'org_openpsa_members_grid';
            $this->_group = new org_openpsa_contacts_group_dba($args[0]);
            $data['group'] = $this->_group;
            $data['provider_url'] .= 'members/' . $this->_group->guid . '/';
        }

        $data['grid'] = $this->_provider->get_grid($grid_id);

        org_openpsa_widgets_tree::add_head_elements();

        $workflow = $this->get_workflow('datamanager');
        if (midcom::get()->auth->can_user_do('midgard:create', null, midcom_db_person::class)) {
            $this->_view_toolbar->add_item($workflow->get_button($this->router->generate('user_create'), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create person'),
                MIDCOM_TOOLBAR_GLYPHICON => 'user',
            ]));
        }

        if (midcom::get()->auth->can_user_do('midgard:create', null, midcom_db_group::class)) {
            $this->_view_toolbar->add_item($workflow->get_button($this->router->generate('group_create'), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create group'),
                MIDCOM_TOOLBAR_GLYPHICON => 'group',
            ]));
        }

        return $this->show('users-grid');
    }

    /**
     * Lists users in JSON format
     *
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_json($handler_id, array $args, array &$data)
    {
        midcom::get()->skip_page_style = true;
        $data['provider'] = $this->_provider;
        if (count($args) == 1) {
            $this->_group = new org_openpsa_contacts_group_dba($args[0]);
        }

        return $this->show('users-grid-json');
    }

    /**
     * Get querybuilder for JSON user list
     */
    public function get_qb($field = null, $direction = 'ASC', array $search = [])
    {
        $qb = midcom_db_person::new_collector();

        if ($this->_group) {
            $qb->get_doctrine()
                ->leftJoin('midgard_member', 'm', Join::WITH, 'm.uid = c.id')
                ->setParameter('gid', $this->_group->id);
            $qb->get_current_group()->add('m.gid = :gid');
        }

        if (!empty($search)) {
            foreach ($search as $field => $value) {
                if ($field == 'username') {
                    midcom_core_account::add_username_constraint($qb, 'LIKE', $value . '%');
                } else {
                    $qb->add_constraint($field, 'LIKE', $value . '%');
                }
            }
        }

        if (!is_null($field)) {
            if ($field == 'username') {
                midcom_core_account::add_username_order($qb, $direction);
            } else {
                $qb->add_order($field, $direction);
            }
        }
        $qb->add_order('lastname');
        $qb->add_order('firstname');
        $qb->add_order('id');
        return $qb;
    }

    /**
     * Prepares user data for JSON display
     */
    public function get_row(midcom_core_dbaobject $user)
    {
        $link = $this->router->generate('user_view', ['guid' => $user->guid]);
        $entry = [];
        $entry['id'] = $user->id;
        $lastname = trim($user->lastname);
        if (empty($lastname)) {
            $lastname = $this->_l10n->get('person') . ' #' . $user->id;
        }
        $entry['lastname'] = "<a href='" . $link . "'>" . $lastname . "</a>";
        $entry['index_lastname'] = $user->lastname;
        $entry['firstname'] = "<a href='" . $link . "'>" . $user->firstname . "</a>";
        $entry['index_firstname'] = $user->firstname;
        $account = new midcom_core_account($user);
        $entry['username'] = $account->get_username();
        $entry['email'] = $user->email;

        return $entry;
    }
}
