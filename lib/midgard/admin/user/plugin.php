<?php
/**
 * @package midgard.admin.user
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * user editor interface
 *
 * @package midgard.admin.user
 */
class midgard_admin_user_plugin extends midcom_baseclasses_components_plugin
{
    public function _on_initialize()
    {
        midcom::get()->auth->require_user_do('midgard.admin.user:access', null, 'midgard_admin_user_plugin');
    }

    /**
     * Generate one password
     *
     * @param int $length
     */
    public static function generate_password($length = 8, $no_similars = true)
    {
        if ($no_similars) {
            $options = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        } else {
            $options = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        }

        return midcom_helper_misc::random_string($length, $options);
    }
}
