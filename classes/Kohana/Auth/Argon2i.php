<?php
/**
 * Created by PhpStorm.
 * User: nzpetter
 * Date: 22.03.2018
 * Time: 19:53
 */

class Kohana_Auth_Argon2i extends Auth
{
    const REMEMBER_TOKEN = 'remember-token';
    const PASSWORD_RESET = 'password-reset';
    const API_TOKEN = 'api-token';

    /**
     * Checks if a session is active.
     *
     * @param   mixed $role Role name string, role Jelly object, or array with role names
     * @return  boolean
     */
    public function logged_in($role = NULL)
    {
        // Get the user from the session
        $user = $this->get_user();
        if (!$user)
        {
            return FALSE;
        }
        if ($user instanceof Model_User AND $user->loaded())
        {
            // If we don't have a roll no further checking is needed
            if (!$role)
            {
                return TRUE;
            }
            if (is_array($role))
            {
                // Get all the roles
                $roles = Jelly::factory('role')->get_role_ids($role);
                // Make sure all the roles are valid ones
                if (count($roles) !== count($role))
                {
                    return FALSE;
                }
            }
            else
            {
                if (!is_object($role))
                {
                    // Load the role
                    $roles = Jelly::factory('role')->get_role($role);
                    if (!$roles->loaded())
                    {
                        return FALSE;
                    }
                }
            }
            return $user->has('roles', $roles);
        }
    }

    /**
     * Logs a user in.
     *
     * @param   string $user username
     * @param   string $password password
     * @param   boolean $remember enable autologin
     * @return  boolean
     */
    protected function _login($user, $password, $remember)
    {
        if (!is_object($user))
        {
            $username = $user;
            // Load the user
            $user = Jelly::factory('user')->get_user($username);
        }

        // If the passwords match, perform a login
        if (password_verify($password, $user->password) AND $user->loaded() AND $user->has('roles', Jelly::factory('role')->get_role('login')))
        {
            if ($remember === TRUE)
            {
                // Token data
                $data = array(
                    'user_id' => $user->id,
                    'expires' => time() + $this->_config['lifetime'],
                    'user_agent' => sha1(Request::$user_agent),
                    'type' => self::REMEMBER_TOKEN
                );
                // Create a new autologin token
                $token = Jelly::factory('user_token')->create_token($data);
                // Set the autologin cookie
                Cookie::set('authautologin', $token->token, strtotime('+1 day') - time());
            }
            // Finish the login
            $this->complete_login($user);
            if (password_needs_rehash($user->password, PASSWORD_ARGON2I))
            {
                $user->password = $password;
                $user->save();
            }
            return TRUE;
        }
        // Login failed
        return FALSE;
    }

    /**
     * Forces a user to be logged in, without specifying a password.
     *
     * @param   mixed $user username string, or user Jelly object
     * @param   boolean $mark_session_as_forced
     *                           mark the session as forced
     * @return void
     */
    public function force_login($user, $mark_session_as_forced = FALSE): void
    {
        if (!is_object($user))
        {
            $username = $user;
            // Load the user
            $user = Jelly::factory('user')->get_user($username);
        }
        if ($mark_session_as_forced === TRUE)
        {
            // Mark the session as forced, to prevent users from changing account information
            $this->_session->set('auth_forced', TRUE);
        }
        // Run the standard completion
        $this->complete_login($user);
    }

    /**
     * Logs a user in, based on the authautologin cookie.
     *
     * @return  mixed
     */
    public function auto_login()
    {
        if ($token = Cookie::get('authautologin'))
        {
            // Load the token and user
            $token = Jelly::factory('user_token')->get_token($token);
            if ($token->loaded() AND $token->user->loaded())
            {
                if ($token->user_agent === sha1(Request::$user_agent))
                {
                    // Save the token to create a new unique token
                    $token->save();
                    // Set the new token
                    Cookie::set('authautologin', $token->token, $token->expires - time());
                    // Complete the login with the found data
                    $this->complete_login($token->user);
                    // Automatic login was successful
                    return $token->user;
                }
                // Token is invalid
                $token->delete();
            }
        }
        return FALSE;
    }

    /**
     * Gets the currently logged in user from the session (with auto_login check).
     * Returns FALSE if no user is currently logged in.
     *
     * @return  mixed
     */
    public function get_user($default = NULL)
    {
        $user = parent::get_user($default);
        if (!$user)
        {
            // check for "remembered" login
            $user = $this->auto_login();
        }
        return $user;
    }

    /**
     * Log a user out and remove any autologin cookies.
     *
     * @param   boolean $destroy completely destroy the session
     * @param    boolean $logout_all remove all tokens for user
     * @return  boolean
     */
    public function logout($destroy = FALSE, $logout_all = FALSE)
    {
        // Set by force_login()
        $this->_session->delete('auth_forced');
        if ($token = Cookie::get('authautologin'))
        {
            // Delete the autologin cookie to prevent re-login
            Cookie::delete('authautologin');
            // Clear the autologin token from the database
            $token = Jelly::factory('user_token')->get_token($token);
            if ($token->loaded() AND $logout_all)
            {
                Jelly::factory('user')->delete_tokens($token->user->id);
            }
            elseif ($token->loaded())
            {
                $token->delete();
            }
        }
        return parent::logout($destroy);
    }

    /**
     * Get the stored password for a username.
     *
     * @param   mixed $user username string, or user Jelly object
     * @return  string
     */
    public function password($user)
    {
        if (!is_object($user))
        {
            $username = $user;
            // Load the user
            $user = Jelly::factory('user')->get_user($username);
        }
        return $user->password;
    }

    /**
     * Complete the login for a user by incrementing the logins and setting
     * session data: user_id, username, roles.
     *
     * @param   object $user Jelly object
     * @return bool
     */
    protected function complete_login($user): bool
    {
        $user->complete_login();
        return parent::complete_login($user);
    }

    /**
     * Compare password with original (hashed). Works for current (logged in) user
     *
     * @param   string $password
     * @return  boolean
     */
    public function check_password($password)
    {
        $user = $this->get_user();
        if (!$user)
        {
            return FALSE;
        }
        return password_verify($password, $user->password);
    }

    /**
     * Hash the password
     * @param String $str
     * @return String
     */
    public function hash($str)
    {
        return password_hash($str, PASSWORD_ARGON2I);
    }
}