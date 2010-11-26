<?php
/**
 * @package org.openpsa.contacts
 * @author The Midgard Project, http://www.midgard-project.org
 * @version $Id: mark.php 22916 2009-07-15 09:53:28Z flack $
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Cron handler for clearing tokens from old send receipts
 * @package org.openpsa.contacts
 */
class org_openpsa_contacts_cron_duplicates_mark extends midcom_baseclasses_components_cron_handler
{
    /**
     * Find possible duplicates and mark them
     */
    function _on_execute()
    {
        debug_add('_on_execute called');
        if (!$this->_config->get('enable_duplicate_search'))
        {
            debug_add('Duplicate search disabled, aborting', MIDCOM_LOG_INFO);
            return;
        }

        $_MIDCOM->auth->request_sudo('org.openpsa.contacts');
        ignore_user_abort();

        $dfinder = new org_openpsa_contacts_duplicates();
        $dfinder->config =& $this->_config;
        $dfinder->mark_all(false);

        $_MIDCOM->auth->drop_sudo();

        debug_add('Done');
    }
}
?>