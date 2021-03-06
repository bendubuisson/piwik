<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Login;

use Exception;
use Piwik\AuthResult;
use Piwik\Config;
use Piwik\Cookie;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugins\UsersManager\API;
use Piwik\Plugins\UsersManager\Model;
use Piwik\ProxyHttp;
use Piwik\Session;

/**
 *
 */
class Auth implements \Piwik\Auth
{
    protected $login = null;
    protected $token_auth = null;

    /**
     * Authentication module's name, e.g., "Login"
     *
     * @return string
     */
    public function getName()
    {
        return 'Login';
    }

    /**
     * Authenticates user
     *
     * @return AuthResult
     */
    public function authenticate()
    {
        if (is_null($this->login)) {
            $model = new Model();
            $user  = $model->getUserByTokenAuth($this->token_auth);

            if (!empty($user['login'])) {
                $code = $user['superuser_access'] ? AuthResult::SUCCESS_SUPERUSER_AUTH_CODE : AuthResult::SUCCESS;

                return new AuthResult($code, $user['login'], $this->token_auth);
            }
        } else if (!empty($this->login)) {
            $model = new Model();
            $user  = $model->getUser($this->login);

            if (!empty($user['token_auth'])
                && (($this->getHashTokenAuth($this->login, $user['token_auth']) === $this->token_auth)
                    || $user['token_auth'] === $this->token_auth)
            ) {
                $this->setTokenAuth($user['token_auth']);
                $code = !empty($user['superuser_access']) ? AuthResult::SUCCESS_SUPERUSER_AUTH_CODE : AuthResult::SUCCESS;

                return new AuthResult($code, $this->login, $user['token_auth']);
            }
        }

        return new AuthResult(AuthResult::FAILURE, $this->login, $this->token_auth);
    }

    /**
     * Authenticates the user and initializes the session.
     */
    public function initSession($login, $md5Password, $rememberMe)
    {
        $this->regenerateSessionId();

        $authResult = $this->doAuthenticateSession($login, $md5Password);

        if (!$authResult->wasAuthenticationSuccessful()) {
            $this->processFailedSession($rememberMe);
        } else {
            $this->processSuccessfullSession($login, $authResult->getTokenAuth(), $rememberMe);
        }
    }

    /**
     * Accessor to set login name
     *
     * @param string $login user login
     */
    public function setLogin($login)
    {
        $this->login = $login;
    }

    /**
     * Accessor to set authentication token
     *
     * @param string $token_auth authentication token
     */
    public function setTokenAuth($token_auth)
    {
        $this->token_auth = $token_auth;
    }

    /**
     * Accessor to compute the hashed authentication token
     *
     * @param string $login user login
     * @param string $token_auth authentication token
     * @return string hashed authentication token
     */
    public function getHashTokenAuth($login, $token_auth)
    {
        return md5($login . $token_auth);
    }

    /**
     * @param $login
     * @param $md5Password
     * @return AuthResult
     * @throws \Exception
     */
    protected function doAuthenticateSession($login, $md5Password)
    {
        $tokenAuth = API::getInstance()->getTokenAuth($login, $md5Password);

        $this->setLogin($login);
        $this->setTokenAuth($tokenAuth);
        $authResult = $this->authenticate();
        return $authResult;
    }

    /**
     * @param $rememberMe
     * @return Cookie
     */
    protected function getAuthCookie($rememberMe)
    {
        $authCookieName = Config::getInstance()->General['login_cookie_name'];
        $authCookieExpiry = $rememberMe ? time() + Config::getInstance()->General['login_cookie_expire'] : 0;
        $authCookiePath = Config::getInstance()->General['login_cookie_path'];
        $cookie = new Cookie($authCookieName, $authCookieExpiry, $authCookiePath);
        return $cookie;
    }

    /**
     * Executed when the session could not authenticate
     * @param $rememberMe
     * @throws \Exception
     */
    protected function processFailedSession($rememberMe)
    {
        $cookie = $this->getAuthCookie($rememberMe);
        $cookie->delete();
        throw new Exception(Piwik::translate('Login_LoginPasswordNotCorrect'));
    }

    /**
     * Executed when the session was successfully authenticated
     * @param $login
     * @param $tokenAuth
     * @param $rememberMe
     */
    protected function processSuccessfullSession($login, $tokenAuth, $rememberMe)
    {
        $cookie = $this->getAuthCookie($rememberMe);
        $cookie->set('login', $login);
        $cookie->set('token_auth', $this->getHashTokenAuth($login, $tokenAuth));
        $cookie->setSecure(ProxyHttp::isHttps());
        $cookie->setHttpOnly(true);
        $cookie->save();

        // remove password reset entry if it exists
        Login::removePasswordResetInfo($login);
    }

    protected function regenerateSessionId()
    {
        Session::regenerateId();
    }
}
