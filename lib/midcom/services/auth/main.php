<?php
/**
 * @package midcom.services
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Main Authentication/Authorization service class, it provides means to authenticate
 * users and to check for permissions.
 *
 * <b>Authentication</b>
 *
 * Whenever the system successfully creates a new login session (during auth service startup),
 * it checks whether the key <i>midcom_services_auth_login_success_url</i> is present in the HTTP
 * Request data. If this is the case, it relocates to the URL given in it. This member isn't set
 * by default in the MidCOM core, it is intended for custom authentication forms. The MidCOM
 * relocate function is used to for relocation, thus you can take full advantage of the
 * convenience functions in there. See midcom_application::relocate() for details.
 *
 * <b>Checking Privileges</b>
 *
 * This class overs various methods to verify the privilege state of a user, all of them prefixed
 * with can_* for privileges and is_* for membership checks.
 *
 * Each function is available in a simple check version, which returns true or false, and a
 * require_* prefixed variant, which has no return value. The require variants of these calls
 * instead check if the given condition is met, if yes, they return silently, otherwise they
 * throw an access denied error.
 *
 * @todo Fully document authentication.
 * @package midcom.services
 */
class midcom_services_auth
{
    /**
     * The currently authenticated user or null in case of anonymous access.
     * It is to be considered read-only.
     *
     * @var midcom_core_user
     */
    public $user = null;

    /**
     * Admin user level state. This is true if the currently authenticated user is an
     * Midgard Administrator, false otherwise.
     *
     * This effectively maps to midcom_connection::is_admin(); but it is suggested to use the auth class
     * for consistency reasons nevertheless.
     *
     * @var boolean
     */
    public $admin = false;

    /**
     * This is a reference to the login session management system.
     *
     * @var midcom_services_auth_sessionmgr
     */
    public $sessionmgr = null;

    /**
     * This is a reference to the ACL management system.
     *
     * @var midcom_services_auth_acl
     */
    public $acl = null;

    /**
     * Internal cache of all loaded groups, indexed by their identifiers.
     *
     * @var Array
     */
    private $_group_cache = Array();

    /**
     * Internal cache of all loaded users, indexed by their identifiers.
     *
     * @var Array
     */
    private $_user_cache = Array();

    /**
     * This flag indicates if sudo mode is active during execution. This will only be the
     * case if the sudo system actually grants this privileges, and only until components
     * release the rights again. This does override the full access control system at this time
     * and essentially give you full admin privileges (though this might change in the future).
     *
     * Note, that this is no boolean but an int, otherwise it would be impossible to trace nested
     * sudo invocations, which are quite possible with multiple components calling each others
     * callback. A value of 0 indicates that sudo is inactive. A value greater then zero indicates
     * sudo mode is active, with the count being equal to the depth of the sudo callers.
     *
     * It is thus still safely possible to evaluate this member in a boolean context to check
     * for an enabled sudo mode.
     *
     * @var int
     * @see request_sudo()
     * @see drop_sudo()
     */
    private $_component_sudo = 0;

    /**
     * A reference to the authentication backend we should use by default.
     *
     * @var midcom_services_auth_backend
     */
    private $_auth_backend = null;

    /**
     * A reference to the authentication frontend we should use by default.
     *
     * @var midcom_services_auth_frontend
     */
    private $_auth_frontend = null;

    /**
     * Flag, which is set to true if the system encountered any new login credentials
     * during startup. If this is true, but no user is authenticated, login did fail.
     *
     * The variable is to be considered read-only.
     *
     * @var boolean
     */
    public $auth_credentials_found = false;

    /**
     * Initialize the service:
     *
     * - Start up the login session service
     * - Load the core privileges.
     * - Initialize to the Midgard Authentication, then synchronize with the auth
     *   drivers' currently authenticated user overriding the Midgard Auth if
     *   necessary.
     */
    function initialize()
    {
        $this->sessionmgr = new midcom_services_auth_sessionmgr($this);
        $this->acl = new midcom_services_auth_acl($this);

        $this->_initialize_user_from_midgard();
        $this->_prepare_authentication_drivers();

        if (! $this->_check_for_new_login_session())
        {
            // No new login detected, so we check if there is a running session.
            $this->_check_for_active_login_session();
        }
    }

    /**
     * Internal startup helper, checks if the current authentication fronted has new credentials
     * ready. If yes, it processes the login accordingly.
     *
     * @return boolean Returns true, if a new login session was created, false if no credentials were found.
     */
    private function _check_for_new_login_session()
    {
        $credentials = $this->_auth_frontend->read_authentication_data();
        if (! $credentials)
        {
            return false;
        }

        $this->auth_credentials_found = true;

        // Try to start up a new session, this will authenticate as well.
        if (! $this->_auth_backend->create_login_session($credentials['username'], $credentials['password']))
        {
            debug_add('The login information passed to the system was invalid.', MIDCOM_LOG_ERROR);
            debug_add("Username was {$credentials['username']}");
            // No password logging for security reasons.

            if (   !empty($GLOBALS['midcom_config']['auth_failure_callback'])
                && is_callable($GLOBALS['midcom_config']['auth_failure_callback']))
            {
                debug_print_r('Calling auth failure callback: ', $GLOBALS['midcom_config']['auth_failure_callback']);
                // Calling the failure function with the username as a parameter. No password sended to the user function for security reasons
                call_user_func($GLOBALS['midcom_config']['auth_failure_callback'], $credentials['username']);
            }

            return false;
        }

        debug_add('Authentication was successful, we have a new login session now. Updating timestamps');

        $this->_sync_user_with_backend();

        $person_class = $GLOBALS['midcom_config']['person_class'];
        $person = new $person_class($this->user->guid);
        if (   $GLOBALS['midcom_config']['auth_save_prev_login']
            && $person->parameter('midcom', 'last_login'))
        {
            $person->parameter('midcom', 'prev_login', $person->parameter('midcom', 'last_login'));
        }

        $person->parameter('midcom', 'last_login', time());

        if (! $person->parameter('midcom', 'first_login'))
        {
            $person->parameter('midcom', 'first_login', time());
        }

        if (   !empty($GLOBALS['midcom_config']['auth_success_callback'])
            && is_callable($GLOBALS['midcom_config']['auth_success_callback']))
        {
            debug_print_r('Calling auth success callback:', $GLOBALS['midcom_config']['auth_success_callback']);
            // Calling the success function. No parameters, because authenticated user is stored in midcom_connection
            call_user_func($GLOBALS['midcom_config']['auth_success_callback']);
        }

        // There was form data sent before authentication was re-required
        if (   isset($_POST['restore_form_data'])
            && isset($_POST['restored_form_data']))
        {
            foreach ($_POST['restored_form_data'] as $key => $string)
            {
                $value = @unserialize(base64_decode($string));
                $_POST[$key] = $value;
                $_REQUEST[$key] = $value;
            }
        }

        // Now we check whether there is a success-relocate URL given somewhere.
        if (array_key_exists('midcom_services_auth_login_success_url', $_REQUEST))
        {
            if (isset($_MIDCOM))
            {
                $_MIDCOM->relocate($_REQUEST['midcom_services_auth_login_success_url']);
            }
            else
            {
                _midcom_header("Location: {$_REQUEST['midcom_services_auth_login_success_url']}");
                _midcom_stop_request();
            }
            // This will exit.
        }
        return true;
    }

    /**
     * Internal helper, synchronizes the main service class with the authentication state
     * of the authentication backend.
     */
    function _sync_user_with_backend()
    {
        $this->user =& $this->_auth_backend->user;
        // This check is a bit fuzzy but will work as long as MidgardAuth is in sync with
        // MidCOM auth.
        if (   midcom_connection::is_admin()
            || $_MIDGARD['root'])
        {
            $this->admin = true;
        }
        else
        {
            $this->admin = false;
        }
    }

    /**
     * Internal startup helper, checks the currently running authentication backend for
     * a running login session.
     */
    private function _check_for_active_login_session()
    {
        if (! $this->_auth_backend->read_login_session())
        {
            return;
        }

        if (! $this->sessionmgr->authenticate_session($this->_auth_backend->session_id))
        {
            debug_add('Failed to re-authenticate a previous login session, not changing credentials.');
            return;
        }

        $this->_sync_user_with_backend();
    }

    /**
     * Internal startup helper, synchronizes the authenticated user with the Midgard Authentication
     * for startup. This will be overridden by MidCOM Auth, but is there for compatibility reasons.
     */
    private function _initialize_user_from_midgard()
    {
        if (   midcom_connection::get_user()
            && $user = $this->get_user(midcom_connection::get_user()))
        {
            $this->user = $user;
            if (   midcom_connection::is_admin()
                || $_MIDGARD['root'])
            {
                $this->admin = true;
            }
        }
    }

    /**
     * Internal startup helper, loads all configured authentication drivers.
     */
    private function _prepare_authentication_drivers()
    {
        $classname = "midcom_services_auth_backend_{$GLOBALS['midcom_config']['auth_backend']}";
        // dont prepend
        if (strpos($GLOBALS['midcom_config']['auth_backend'], "_"))
        {
            $classname = $GLOBALS['midcom_config']['auth_backend'];
        }
        $this->_auth_backend = new $classname($this);

        $classname = "midcom_services_auth_frontend_{$GLOBALS['midcom_config']['auth_frontend']}";
        // dont prepend
        if (strpos($GLOBALS['midcom_config']['auth_frontend'], "_"))
        {
            $classname = $GLOBALS['midcom_config']['auth_frontend'];
        }
        $this->_auth_frontend = new $classname();
    }

    /**
     * Checks whether a user has a certain privilege on the given content object.
     * Works on the currently authenticated user by default, but can take another
     * user as an optional argument.
     *
     * @param string $privilege The privilege to check for
     * @param MidgardObject $content_object A Midgard Content Object
     * @param midcom_core_user $user The user against which to check the privilege, defaults to the currently authenticated user.
     *     You may specify "EVERYONE" instead of an object to check what an anonymous user can do.
     * @return boolean True if the privilege has been granted, false otherwise.
     */
    function can_do($privilege, $content_object, $user = null)
    {
        if (!is_object($content_object))
        {
            return false;
        }

        if (   is_null($user)
            && !is_null($this->user)
            && $this->admin)
        {
            // Administrators always have access.
            return true;
        }

        $user_id = $this->acl->get_user_id($user);

        //if we're handed the correct object type, we use it's class right away
        if (midcom::get('dbclassloader')->is_midcom_db_object($content_object))
        {
            $content_object_class = get_class($content_object);
        }
        //otherwise, we assume (hope) that it's a midgard object
        else
        {
            $content_object_class = $_MIDCOM->dbclassloader->get_midcom_class_name_for_mgdschema_object($content_object);
        }

        return $this->acl->can_do_byguid($privilege, $content_object->guid, $content_object_class, $user_id);
    }

    /**
     * Checks, whether the given user have the privilege assigned to him in general.
     * Be aware, that this does not take any permissions overridden by content objects
     * into account. Whenever possible, you should user the can_do() variant of this
     * call therefore. can_user_do is only of interest in cases where you do not have
     * any content object available, for example when creating root topics.
     *
     * @param string $privilege The privilege to check for
     * @param midcom_core_user $user The user against which to check the privilege, defaults to the currently authenticated user,
     *     you may specify 'EVERYONE' here to check what an anonymous user can do.
     * @param string $class Optional parameter to set if the check should take type specific permissions into account. The class must be default constructible.
     * @param string $component Component providing the class
     * @return boolean True if the privilege has been granted, false otherwise.
     */
    function can_user_do($privilege, $user = null, $class = null, $component = null)
    {
        if (is_null($user))
        {
            if ($this->admin)
            {
                // Administrators always have access.
                return true;
            }
            $user =& $this->user;
        }

        if ($this->_component_sudo)
        {
            return true;
        }

        if (   is_string($user)
            && $user == 'EVERYONE')
        {
            $user = null;
        }

        if (!is_null($user))
        {
            if (is_object($class))
            {
                $classname = get_class($class);
            }
            else
            {
                $classname = $class;
            }

            debug_add("Querying privilege {$privilege} for user {$user->id} to class {$classname}");
        }

        return $this->acl->can_do_byclass($privilege, $user, $class, $component);
    }

    /**
     * Returns a full listing of all currently known privileges for a certain object/user
     * combination.
     *
     * The information is cached per object-guid during runtime, so that repeated checks
     * to the same object do not cause repeating checks. Be aware that this means, that
     * new privileges set are not guaranteed to take effect until the next request.
     *
     * @param MidgardObject &$content_object A Midgard Content Object
     * @param midcom_core_user $user The user against which to check the privilege, defaults to the currently authenticated user.
     *     You may specify "EVERYONE" instead of an object to check what an anonymous user can do.
     * @return Array Associative listing of all privileges and their value.
     */
    function get_privileges(&$content_object, $user = null)
    {
        $user_id = $this->acl->get_user_id($user);

        return $this->acl->get_privileges_by_guid($content_object->guid, get_class($content_object), $user_id);
    }

    /**
     * Request superuser privileges for the domain passed.
     *
     * STUB IMPLEMENTATION ONLY, WILL ALWAYS GRANT SUDO.
     *
     * You have to call midcom_services_auth::drop_sudo() as soon as you no longer
     * need the elevated privileges, which will reset the authentication data to the
     * initial credentials.
     *
     * @param string $domain The domain to request sudo for. This is a component name.
     * @return boolean True if admin privileges were granted, false otherwise.
     */
    function request_sudo ($domain = null)
    {
        if (! $GLOBALS['midcom_config']['auth_allow_sudo'])
        {
            debug_add("SUDO is not allowed on this website.", MIDCOM_LOG_ERROR);
            return false;
        }

        if (is_null($domain))
        {
            $domain = $_MIDCOM->get_context_data(MIDCOM_CONTEXT_COMPONENT);
            debug_add("Domain was not supplied, falling back to '{$domain}' which we got from the current component context.");
        }

        if ($domain == '')
        {
            debug_add("SUDO request for an empty domain, this should not happen. Denying sudo.", MIDCOM_LOG_INFO);
            return false;
        }

        $this->_component_sudo++;

        debug_add("Entered SUDO mode for domain {$domain}.", MIDCOM_LOG_INFO);

        return true;
    }

    /**
     * Drops previously acquired superuser privileges.
     *
     * @see request_sudo()
     */
    function drop_sudo()
    {
        if ($this->_component_sudo > 0)
        {
            debug_add('Leaving SUDO mode.');
            $this->_component_sudo--;
        }
        else
        {
            debug_add('Requested to leave SUDO mode, but sudo was already disabled. Ignoring request.', MIDCOM_LOG_INFO);
        }
    }

    public function is_component_sudo()
    {
        return ($this->_component_sudo > 0);
    }

    /**
     * Check, whether a user is member of a given group. By default, the query is run
     * against the currently authenticated user.
     *
     * It always returns true for administrative users.
     *
     * @param mixed $group Group to check against, this can be either a midcom_core_group object or a group string identifier.
     * @param midcom_core_user The user which should be checked, defaults to the current user.
     * @return boolean Indicating membership state.
     */
    function is_group_member($group, $user = null)
    {
        // Default parameter
        if (is_null($user))
        {
            if (is_null($this->user))
            {
                // not authenticated
                return false;
            }
            $user =& $this->user;
        }

        if ($this->admin)
        {
            // Administrators always have access.
            return true;
        }

        return $user->is_in_group($group);
    }

    /**
     * Returns true if there is an authenticated user, false otherwise.
     *
     * @return boolean True if there is a user logged in.
     */
    function is_valid_user()
    {
        return (! is_null($this->user));
    }

    /**
     * Validates that the current user has the given privilege granted on the
     * content object passed to the function.
     *
     * If this is not the case, an Access Denied error is generated, the message
     * defaulting to the string 'access denied: privilege %s not granted' of the
     * MidCOM main L10n table.
     *
     * The check is always done against the currently authenticated user. If the
     * check is successful, the function returns silently.
     *
     * @param string $privilege The privilege to check for
     * @param MidgardObject $content_object A Midgard Content Object
     * @param string $message The message to show if the privilege has been denied.
     */
    function require_do($privilege, &$content_object, $message = null)
    {
        if (!$this->can_do($privilege, $content_object))
        {
            if (is_null($message))
            {
                $string = $_MIDCOM->i18n->get_string('access denied: privilege %s not granted', 'midcom');
                $message = sprintf($string, $privilege);
            }
            $this->access_denied($message);
            // This will exit.
        }
    }

    /**
     * Validates, whether the given user have the privilege assigned to him in general.
     * Be aware, that this does not take any permissions overridden by content objects
     * into account. Whenever possible, you should user the require_do() variant of this
     * call therefore. require_user_do is only of interest in cases where you do not have
     * any content object available, for example when creating root topics.
     *
     * If this is not the case, an Access Denied error is generated, the message
     * defaulting to the string 'access denied: privilege %s not granted' of the
     * MidCOM main L10n table.
     *
     * The check is always done against the currently authenticated user. If the
     * check is successful, the function returns silently.
     *
     * @param string $privilege The privilege to check for
     * @param string $message The message to show if the privilege has been denied.
     * @param string $class Optional parameter to set if the check should take type specific permissions into account. The class must be default constructible.
     */
    function require_user_do($privilege, $message = null, $class = null)
    {
        if (! $this->can_user_do($privilege, null, $class))
        {
            if (is_null($message))
            {
                $string = $_MIDCOM->i18n->get_string('access denied: privilege %s not granted', 'midcom');
                $message = sprintf($string, $privilege);
            }
            $this->access_denied($message);
            // This will exit.
        }
    }


    /**
     * Validates that the current user is a member of the given group.
     *
     * If this is not the case, an Access Denied error is generated, the message
     * defaulting to the string 'access denied: user is not member of the group %s' of the
     * MidCOM main L10n table.
     *
     * The check is always done against the currently authenticated user. If the
     * check is successful, the function returns silently.
     *
     * @param mixed $group Group to check against, this can be either a midcom_core_group object or a group string identifier.
     * @param string $message The message to show if the user is not member of the given group.
     */
    function require_group_member($group, $message = null)
    {
        if (! $this->is_group_member($group))
        {
            if (is_null($message))
            {
                $string = $_MIDCOM->i18n->get_string('access denied: user is not member of the group %s', 'midcom');
                if (is_object($group))
                {
                    $message = sprintf($string, $group->name);
                }
                else
                {
                    $message = sprintf($string, $group);
                }
            }

            $this->access_denied($message);
            // This will exit.
        }
    }

    /**
     * Validates that we currently have admin level privileges, which can either
     * come from the current user, or from the sudo service.
     *
     * If the check is successful, the function returns silently.
     * @param string $message The message to show if the admin level privileges are missing..
     */
    function require_admin_user($message = null)
    {
        if ($message === null)
        {
            $message = $_MIDCOM->i18n->get_string('access denied: admin level privileges required', 'midcom');
        }
        if (   ! $this->admin
            && ! $this->_component_sudo)
        {
            $this->access_denied($message);
            // This will exit.
        }
    }

    /**
     * Validates that there is an authenticated user.
     *
     * If this is not the case, the regular login page is shown automatically, see
     * show_login_page() for details..
     *
     * If the check is successful, the function returns silently.
     *
     * @param string $method Preferred authentication method: form or basic
     */
    function require_valid_user($method = 'form')
    {
        debug_print_function_stack("require_valid_user called at this level");
        if (!$this->is_valid_user())
        {
            switch ($method)
            {
                case 'basic':
                    $this->_http_basic_auth();
                    break;

                case 'form':
                default:
                    $this->show_login_page();
                    // This will exit.
            }
        }
    }

    /**
     * Handles HTTP Basic authentication
     */
    function _http_basic_auth()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']))
        {
            _midcom_header("WWW-Authenticate: Basic realm=\"Midgard\"");
            _midcom_header('HTTP/1.0 401 Unauthorized');
            // TODO: more fancy 401 output ?
            echo "<h1>Authorization required</h1>\n";
            $_MIDCOM->finish();
            _midcom_stop_request();
        }
        else
        {
            if (!$this->sessionmgr->create_login_session($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']))
            {
                // Wrong password: Recurse until auth ok or user gives up
                unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
                $this->_http_basic_auth();
            }
            // Figure out how to update midcom auth status
            $_MIDCOM->auth->_initialize_user_from_midgard();
        }
    }

    /**
     * Factory Method: Resolves any assignee identifier known by the system into an appropriate
     * user/group object.
     *
     * You must adhere the reference that is returned, otherwise the internal caching
     * and runtime state strategy will fail.
     *
     * @param string $id A valid user or group identifier useable as assignee (e.g. the $id member
     *     of any midcom_core_user or midcom_core_group object).
     * @return object A reference to the corresponding object or false on failure.
     */
    function get_assignee($id)
    {
        $result = null;

        $parts = explode(':', $id);

        switch ($parts[0])
        {
            case 'user':
                $result = $this->get_user($id);
                break;

            case 'group':
                $result = $this->get_group($id);
                break;

            default:
                debug_add("The identifier {$id} cannot be resolved into an assignee, it cannot be mapped to a type.", MIDCOM_LOG_WARN);
                break;
        }

        return $result;
    }

    /**
     * This is a wrapper for get_user, which allows user retrieval by its name.
     * If the username is unknown, false is returned.
     *
     * @param string $name The name of the user to look up.
     * @return midcom_core_user A reference to the user object matching the username,
     *     or false if the username is unknown.
     */
    function get_user_by_name($name)
    {
        if (method_exists('midgard_user', 'login'))
        {
            //Midgard2
            $mc = new midgard_collector('midgard_user', 'login', $name);
            $mc->set_key_property('person');
            $mc->add_constraint('authtype', '=', $GLOBALS['midcom_config']['auth_type']);
        }
        else
        {
            //Midgard1
            $mc = new midgard_collector($GLOBALS['midcom_config']['person_class'], 'username', $name);
            $mc->set_key_property('guid');
        }
        $mc->execute();
        $keys = $mc->list_keys();
        if (count($keys) != 1)
        {
            return false;
        }

        $person = new $GLOBALS['midcom_config']['person_class'](key($keys));

        return $this->get_user($person);
    }

    /**
     * This is a wrapper for get_user, which allows user retrieval by its email address.
     * If the email is empty or unknown, false is returned.
     *
     * @param string $email The email of the user to look up.
     * @return array|midcom_core_user A reference to the user object matching the email, array if multiple matches
     *     or false if the email is unknown.
     */
    function get_user_by_email($email)
    {
        static $persons_by_email = array();

        if (empty($email))
        {
            return false;
        }

        if (array_key_exists($email, $persons_by_email))
        {
            return $persons_by_email[$email];
        }

        // Seek user based on the primary email field
        $qb = new midgard_query_builder($GLOBALS['midcom_config']['person_class']);
        $qb->add_constraint('email', '=', $email);

        // FIXME: Some sites like maemo.org instead of deleting users just remove their account and prefix firstname by "DELETE "
        $qb->add_constraint('firstname', 'NOT LIKE', 'DELETE %');

        $results = @$qb->execute();

        if (   !$results
            || count($results) == 0)
        {
            // Try finding user based on the other email fields
            $person_guids = array();
            $mc = new midgard_collector('midgard_parameter', 'value', $email);
            $mc->set_key_property('parentguid');
            $mc->add_constraint('domain', '=', 'org.imc.vcard:email');
            $mc->execute();
            $guids = $mc->list_keys();
            foreach ($guids as $guid => $array)
            {
                $person_guids[] = $guid;
            }

            if (empty($person_guids))
            {
                return false;
            }

            $qb = new midgard_query_builder($GLOBALS['midcom_config']['person_class']);
            $qb->add_constraint('guid', 'IN', $person_guids);

            // FIXME: Some sites like maemo.org instead of deleting users just remove their account and prefix firstname by "DELETE "
            $qb->add_constraint('firstname', 'NOT LIKE', 'DELETE %');

            $results = @$qb->execute();

            if (empty($results))
            {
                $persons_by_email[$email] = false;
                return false;
            }
        }

        if (count($results) > 1)
        {
            $persons_by_email[$email] = array();
            foreach ($results as $result)
            {
                $persons_by_email[$email][] = $this->get_user($result);
            }
            return $persons_by_email[$email];
        }

        $persons_by_email[$email] = $this->get_user($results[0]);
        return $persons_by_email[$email];
    }

    /**
     * This is a wrapper for get_group, which allows Midgard Group retrieval by its name.
     * If the group name is unknown, false is returned.
     *
     * In the case that more then one
     * group matches the given name, the first one is returned. Note, that this should not
     * happen as midgard group names should be unique according to the specs.
     *
     * @param string $name The name of the group to look up.
     * @return midcom_core_group A reference to the group object matching the group name,
     *     or false if the group name is unknown.
     */
    function & get_midgard_group_by_name($name)
    {
        $qb = new midgard_query_builder('midgard_group');
        $qb->add_constraint('name', '=', $name);

        $result = @$qb->execute();
        if (   ! $result
            || count($result) == 0)
        {
            $result = false;
            return $result;
        }
        $grp = $this->get_group($result[0]);
        return $grp;
    }

    /**
     * Factory Method: Loads a user from the database and returns an object instance.
     *
     * You must adhere the reference that is returned, otherwise the internal caching
     * and runtime state strategy will fail.
     *
     * @param mixed $id A valid identifier for a MidgardPerson: An existing midgard_person class
     *     or subclass thereof, a Person ID or GUID or a midcom_core_user identifier.
     * @return midcom_core_user A reference to the user object matching the identifier or false on failure.
     */
    function get_user($id)
    {
        $object = null;
        if (is_double($id))
        {
            // This is some crazy workaround for cases where the ID passed is a double
            // (coming from midcom_connection::get_user() possibly) and is_object($id), again for
            // whatever reason, evaluates to true for that object...
            $id = (int) $id;
        }
        else if (is_object($id))
        {
            if (is_a($id, 'midcom_db_person'))
            {
                $id = $id->id;
                $object = null;
            }
            elseif (is_a($id, $GLOBALS['midcom_config']['person_class']))
            {
                $object = $id;
                $id = $object->id;
            }
            else
            {
                debug_print_type('The passed argument was an object of an unsupported type:', $id, MIDCOM_LOG_WARN);
                debug_print_r('Complete object dump:', $id);

                return false;
            }
        }
        else if (   ! is_string($id)
                 && ! is_integer($id))
        {
            debug_print_type('The passed argument was an object of an unsupported type:', $id, MIDCOM_LOG_WARN);
            debug_print_r('Complete object dump:', $id);

            return false;
        }
        if (! array_key_exists($id, $this->_user_cache))
        {
            try
            {
                if (is_null($object))
                {
                    $this->_user_cache[$id] = new midcom_core_user($id);
                }
                else
                {
                    $this->_user_cache[$id] = new midcom_core_user($object);
                }
            }
            catch (midcom_error $e)
            {
                // Keep it silent while missing user object can mess here
                $this->_user_cache[$id] = false;
            }
        }

        return $this->_user_cache[$id];
    }

    /**
     * Returns a midcom_core_group instance. Valid arguments are either a valid group identifier
     * (group:...), any valid identifier for the midcom_core_group
     * constructor or a valid object of that type.
     *
     * You must adhere the reference that is returned, otherwise the internal caching
     * and runtime state strategy will fail.
     *
     * @param mixed $id The identifier of the group as outlined above.
     * @return midcom_core_group A group object instance matching the identifier, or false on failure.
     */
    function get_group($id)
    {
        $group = false;
        if (   is_object($id)
            && (   is_a($id, 'midcom_db_group')
                || is_a($id, 'midgard_group')))
        {
            $object = $id;
            $id = "group:{$id->guid}";
            if (! array_key_exists($id, $this->_group_cache))
            {
                $this->_group_cache[$id] = new midcom_core_group($object->id);
            }
        }
        else if (is_string($id))
        {
            if (! array_key_exists($id, $this->_group_cache))
            {
                $id_parts = explode(':', $id);
                if (count($id_parts) == 2)
                {
                    // This is a (v)group:... identifier
                    switch ($id_parts[0])
                    {
                        case 'group':
                            try
                            {
                                $this->_group_cache[$id] = new midcom_core_group($id_parts[1]);
                            }
                            catch (midcom_error $e)
                            {
                                $e->log();
                                $this->_group_cache[$id] = false;
                            }
                            break;

                        default:
                            $this->_group_cache[$id] = false;
                            debug_add("The group type identifier {$id_parts[0]} is unknown, no group was loaded.", MIDCOM_LOG_WARN);
                            break;
                    }
                }
                else
                {
                    // This must be a group ID, lets hope that the group constructor
                    // can take it.
                    try
                    {
                        $tmp = new midcom_core_group($id);
                        $id = $tmp->id;
                        $this->_group_cache[$id] = $tmp;
                    }
                    catch (midcom_error $e)
                    {
                        $this->_group_cache[$id] = false;
                        debug_add("The group type identifier {$id} is of an invalid type, no group was loaded.", MIDCOM_LOG_WARN);
                    }
                }
            }
        }
        else if (is_int($id))
        {
            // Looks like an object ID, again we try the group constructor.
            try
            {
                $tmp = new midcom_core_group($id);
                $id = $tmp->id;
                $this->_group_cache[$id] = $tmp;
            }
            catch (midcom_error $e)
            {
                $this->_group_cache[$id] = false;
                debug_add("The group type identifier {$id} is of an invalid type, no group was loaded.", MIDCOM_LOG_WARN);
            }
        }
        else
        {
            $this->_group_cache[$id] = false;
            debug_add("The group type identifier {$id} is of an invalid type, no group was loaded.", MIDCOM_LOG_WARN);
        }

        return $this->_group_cache[$id];
    }

    /**
     * This call tells the backend to log in.
     */
    public function login($username, $password)
    {
        return $this->_auth_backend->create_login_session($username, $password);
    }

    public function trusted_login($username)
    {
        if ($GLOBALS['midcom_config']['auth_allow_trusted'] !== true)
        {
            debug_add("Trusted logins are prohibited", MIDCOM_LOG_ERROR);
            return false;
        }

        return $this->_auth_backend->create_trusted_login_session($username);
    }

    /**
     * This call clears any authentication state
     */
    function logout()
    {
        $this->drop_login_session();
        $this->admin = false;
        $this->user = null;
    }

    /**
     * This is a limited version of logout: It will just drop the current login session, but keep
     * the current request authenticated.
     *
     * Note, that this call will also drop any information in the PHP Session (if exists). This will
     * leave the request in a clean state after calling this function.
     */
    function drop_login_session()
    {
        if (is_null($this->_auth_backend->user))
        {
            debug_add('The backend has no authenticated user set, so we should be fine, doing the relocate nevertheless though.');
        }
        else
        {
            $this->_auth_backend->logout();
        }

        // Kill the session forcibly:
        @session_start();
        $_SESSION = Array();
        session_destroy();
    }

    function _generate_http_response()
    {
        if (_midcom_headers_sent())
        {
            // We have sent output to browser already, skip setting headers
            return false;
        }

        switch ($GLOBALS['midcom_config']['auth_login_form_httpcode'])
        {
            case 200:
                _midcom_header('HTTP/1.0 200 OK');
                break;

            case 403:
            default:
                _midcom_header('HTTP/1.0 403 Forbidden');
                break;
        }
    }

    /**
     * This is called by throw new midcom_error_forbidden(...) if and only if
     * the headers have not yet been sent. It will display the error message and appends the
     * login form below it.
     *
     * The function will clear any existing output buffer, and the sent page will have the
     * 403 - Forbidden HTTP Status. The login will relocate to the same URL, so it should
     * be mostly transparent.
     *
     * The login message shown depends on the current state:
     * - If an authentication attempt was done but failed, an appropriated wrong user/password
     *   message is shown.
     * - If the user is authenticated, a note that he might have to switch to a user with more
     *   privileges is shown.
     * - Otherwise, no message is shown.
     *
     * This function will exit() unconditionally.
     *
     * If the style element <i>midcom_services_auth_access_denied</i> is defined, it will be shown
     * instead of the default error page. The following variables will be available in the local
     * scope:
     *
     * $title contains the localized title of the page, based on the 'access denied' string ID of
     * the main MidCOM L10n DB. $message will contain the notification what went wrong and
     * $login_warning will notify the user of a failed login. The latter will either be empty
     * or enclosed in a paragraph with the CSS ID 'login_warning'.
     *
     * @link http://www.midgard-project.org/midcom-permalink-c5e99db3cfbb779f1108eff19d262a7c further information about how to style these elements.
     * @param string $message The message to show to the user.
     */
    function access_denied($message)
    {
        debug_print_function_stack("access_denied was called from here:");

        // Determine login message
        $login_warning = '';
        if (! is_null($this->user))
        {
            // The user has insufficient privileges
            $login_warning = $_MIDCOM->i18n->get_string('login message - insufficient privileges', 'midcom');
        }
        else if ($this->auth_credentials_found)
        {
            $login_warning = $_MIDCOM->i18n->get_string('login message - user or password wrong', 'midcom');
        }

        if (   isset($_MIDGARD['config']['ragnaland'])
            && $_MIDGARD['config']['ragnaland'])
        {
            // We're running under Ragnaland, delegate logins to Midgard MVC
            throw new midgardmvc_exception_unauthorized($login_warning);
        }

        $title = $_MIDCOM->i18n->get_string('access denied', 'midcom');

        // Emergency check, if headers have been sent, kill MidCOM instantly, we cannot output
        // an error page at this point (dynamic_load from site style? Code in Site Style, something
        // like that)
        if (_midcom_headers_sent())
        {
            debug_add('Cannot render an access denied page, page output has already started. Aborting directly.', MIDCOM_LOG_INFO);
            echo "<br />{$title}: {$login_warning}";
            $_MIDCOM->finish();
            debug_add("Emergency Error Message output finished, exiting now");
            _midcom_stop_request();
        }

        // Drop any output buffer first.
        $_MIDCOM->cache->content->disable_ob();

        $this->_generate_http_response();

        $_MIDCOM->cache->content->no_cache();

        $_MIDCOM->style->data['midcom_services_auth_access_denied_message'] = $message;
        $_MIDCOM->style->data['midcom_services_auth_access_denied_title'] = $title;
        $_MIDCOM->style->data['midcom_services_auth_access_denied_login_warning'] = $login_warning;

        $_MIDCOM->style->show_midcom('midcom_services_auth_access_denied');

        $_MIDCOM->finish();
        debug_add("Error Page output finished, exiting now");
        _midcom_stop_request();
    }

    /**
     * This function should be used to render the main login form. This does only include the form,
     * no heading or whatsoever.
     *
     * It is recommended to call this function only as long as the headers are not yet sent (which
     * is usually given thanks to MidCOMs output buffering).
     *
     * What gets rendered depends on the authentication frontend, but will usually be some kind
     * of form. The output from the frontend is surrounded by a div tag whose CSS ID is set to
     * 'midcom_login_form'.
     *
     * @link http://www.midgard-project.org/midcom-permalink-c5e99db3cfbb779f1108eff19d262a7c further information about how to style these elements.
     */
    function show_login_form()
    {
        echo "<div id='midcom_login_form'>\n";
        $this->_auth_frontend->show_authentication_form();
        echo "</div>\n";
    }

    /**
     * This will show a complete login page unconditionally and exit afterwards.
     * If the current style has an element called <i>midcom_services_auth_login_page</i>
     * it will be shown instead. The local scope will contain the two variables
     * $title and $login_warning. $title is the localized string 'login' from the main
     * MidCOM L10n DB, login_warning is empty unless there was a failed authentication
     * attempt, in which case it will have a localized warning message enclosed in a
     * paragraph with the ID 'login_warning'.
     *
     * @link http://www.midgard-project.org/midcom-permalink-c5e99db3cfbb779f1108eff19d262a7c further information about how to style these elements.
     */
    function show_login_page()
    {
        // Drop any output buffer first
        $_MIDCOM->cache->content->disable_ob();

        $this->_generate_http_response();

        $_MIDCOM->cache->content->no_cache();

        $title = $_MIDCOM->i18n->get_string('login', 'midcom');

        if (   isset($_MIDGARD['config']['ragnaland'])
            && $_MIDGARD['config']['ragnaland'])
        {
            // We're running under Ragnaland, delegate logins to Midgard MVC
            throw new midgardmvc_exception_unauthorized($title);
        }

        // Determine login warning so that wrong user/pass is shown.
        $login_warning = '';
        if (   $this->auth_credentials_found
            && is_null($this->user))
        {
            $login_warning = $_MIDCOM->i18n->get_string('login message - user or password wrong', 'midcom');
        }

        // Pass our local but very useful variables on to the style element
        $_MIDCOM->style->data['midcom_services_auth_show_login_page_title'] = $title;
        $_MIDCOM->style->data['midcom_services_auth_show_login_page_login_warning'] = $login_warning;

        $_MIDCOM->style->show_midcom('midcom_services_auth_login_page');

        $_MIDCOM->finish();
        _midcom_stop_request();
    }
}
?>
