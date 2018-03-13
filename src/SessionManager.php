<?php
//
// +---------------------------------------------------------------------+
// | CODE INC. SOURCE CODE                                               |
// +---------------------------------------------------------------------+
// | Copyright (c) 2017 - Code Inc. SAS - All Rights Reserved.           |
// | Visit https://www.codeinc.fr for more information about licensing.  |
// +---------------------------------------------------------------------+
// | NOTICE:  All information contained herein is, and remains the       |
// | property of Code Inc. SAS. The intellectual and technical concepts  |
// | contained herein are proprietary to Code Inc. SAS are protected by  |
// | trade secret or copyright law. Dissemination of this information or |
// | reproduction of this material  is strictly forbidden unless prior   |
// | written permission is obtained from Code Inc. SAS.                  |
// +---------------------------------------------------------------------+
//
// Author:   Joan Fabrégat <joan@codeinc.fr>
// Date:     02/03/2018
// Time:     10:45
// Project:  lib-session
//
declare(strict_types = 1);
namespace CodeInc\Session;
use CodeInc\Session\Exceptions\SessionManagerException;
use CodeInc\Session\Handlers\HandlerInterface;
use HansOtt\PSR7Cookies\SetCookie;
use Psr\Http\Message\ServerRequestInterface;


/**
 * Class SessionManager
 *
 * @package CodeInc\Session
 * @author Joan Fabrégat <joan@codeinc.fr>
 */
class SessionManager
{
	public const DEFAULT_NAME = "SID";
	public const DEFAULT_EXPIRE = 60;

    /**
     * Session handler
     *
     * @var HandlerInterface
     */
    private $handler;

    /**
     * Session object
     *
     * @see SessionManager::getSession()
     * @var Session|null
     */
    private $session;

    /**
	 * Session name
	 *
	 * @var string
	 */
	private $name;

    /**
	 * Session lifespan in minutes.
	 *
	 * @var int
	 */
	private $expire;

    /**
	 * Session cookie host
	 *
	 * @var string|null
	 */
	private $cookieHost;

    /**
	 * Session cookie secure
	 *
	 * @var bool
	 */
	private $cookieSecure = false;

    /**
	 * Session cookie path
	 *
	 * @var string|null
	 */
	private $cookiePath;

    /**
	 * Verifies if the session cookie must be HTTP only.
	 *
	 * @var bool
	 */
	private $cookieHttpOnly = true;

	/**
	 * Verifies if the client IP address needs to be validated.
	 *
	 * @var bool
	 */
	private $validateClientIp = false;

	/**
	 * SessionManager constructor.
	 *
	 * @param HandlerInterface $handler
	 * @param null|string $name
	 * @param int|null $expire
	 */
	public function __construct(HandlerInterface $handler, ?string $name = null,
		?int $expire = null)
	{
		$this->handler = $handler;
		$this->name = $name ?? self::DEFAULT_NAME;
		$this->expire = $expire ?? self::DEFAULT_EXPIRE;
	}

	/**
	 * Destructor. Saves the session data.
	 */
	public function __destruct()
	{
		if ($session = $this->getSession()) {
		    // saving the session
			$session->updateLastRequestTime();
            $this->getHandler()->write($session->getId(), $session->getData());

            // one time on 100 we delete the expired sessions
			if (rand(1, 100) == 50) {
				$this->getHandler()->cleanup($this->getExpire() * 60);
				$session[$session::HEADER_CLEAN_UP] = true;
			}
		}
	}

	/**
	 * @return HandlerInterface
	 */
	public function getHandler():HandlerInterface
	{
		return $this->handler;
	}

    /**
     * @param ServerRequestInterface $request
     * @return Session
     * @throws SessionManagerException
     */
	public function start(ServerRequestInterface $request):Session
	{
		try {
            // if the session exists, loading it!
			if (isset($request->getCookieParams()[$this->getName()])) {
			    $id = $request->getCookieParams()[$this->getName()];
                // the session is not valid = we delete the previous one and create a new one.
				if (($data = $this->getHandler()->read($id)) !== null) {
				    $this->session = new Session($this, $id, $data);
				    // if the session is invalid, we destroy the previous one and create a new one
				    if (!$this->isSessionValid($this->session, $request)) {
                        $this->getHandler()->destroy($id);
                        $this->session = Session::factory($this, $request);
                    }
				}

				// if the session can not be loaded, we create a new one
				else {
                    $this->getHandler()->destroy($id);
                    $this->session = Session::factory($this, $request);
                }
			}

			// else creating the session
			else {
                $this->session = Session::factory($this, $request);
			}

			return $this->session;
		}
		catch (\Throwable $exception) {
			throw new SessionManagerException(
				"Error while starting the session",
				$this, null, $exception
			);
		}
	}

    /**
     * Verifies if a session is valid
     *
     * @param Session $session
     * @param ServerRequestInterface $request
     * @return bool
     */
    private function isSessionValid(Session $session, ServerRequestInterface $request):bool
    {
        // if the session is expired
        if (($lastReq = $session->getLastRequestTime())
            && ($lastReq + $session->getSessionManager()->getExpire() * 60) < time())
        {
            return false;
        }

        // if the client IP changed
        if ($this->getValidateClientIp()
            && ($clientIp = $session->getClientIp())
            && isset($request->getServerParams()['REMOTE_ADDR'])
            && $clientIp != $request->getServerParams()['REMOTE_ADDR'])
        {
            return false;
        }

        return true;
    }

    /**
     * Returns the session cookie.
     *
     * @return SetCookie|null
     * @throws SessionManagerException
     */
    public function getSessionCookie():?SetCookie
    {
        try {
            // envoie du cookie de session
            if ($session = $this->getSession()) {
                return new SetCookie(
                    $this->getName(),
                    $session->getId(),
                    (time() + $this->getExpire() * 60),
                    $this->getCookiePath() ?? "/",
                    $this->getCookieHost(),
                    $this->getCookieSecure(),
                    $this->isCookieHttpOnly()
                );
            }

            // sinon destruction du cookie de session
            else {
                return SetCookie::thatDeletesCookie($this->getName());
            }
        }
        catch (\Throwable $exception) {
            throw new SessionManagerException(
                "Error while preparing the session cookie",
                $this, null, $exception
            );
        }
    }

	/**
	 * Stops the session. All session data are destroyed in memory and on the
	 * storage unit through the handler.
	 */
	public function stop():void
	{
		if ($session = $this->getSession()) {
			$this->getHandler()->destroy($session->getId());
			$this->session = null;
		}
	}

	/**
	 * Verifies if the session is started.
	 *
	 * @return bool
	 */
	public function isStarted():bool
	{
		return $this->getSession() !== null;
	}

    /**
     * Returns the current session.
     *
     * @return Session|null
     */
    public function getSession():?Session
    {
        return $this->session;
    }

	/**
	 * @param string $name
	 */
	public function setName(string $name):void
	{
		$this->name = $name;
	}

	/**
	 * @return string
	 */
	public function getName():string
	{
		return $this->name;
	}

	/**
	 * @param int $expire
	 */
	public function setExpire(int $expire):void
	{
		$this->expire = $expire;
	}

	/**
	 * @return int
	 */
	public function getExpire():int
	{
		return $this->expire;
	}

	/**
	 * @param null|string $cookieHost
	 */
	public function setCookieHost(?string $cookieHost):void
	{
		$this->cookieHost = $cookieHost;
	}

	/**
	 * @return null|string
	 */
	public function getCookieHost():?string
	{
		return $this->cookieHost;
	}

	/**
	 * @param bool|null $cookieSecure
	 */
	public function setCookieSecure(?bool $cookieSecure):void
	{
		$this->cookieSecure = $cookieSecure;
	}

	/**
	 * @return bool|null
	 */
	public function getCookieSecure():?bool
	{
		return $this->cookieSecure;
	}

	/**
	 * @param null|string $cookiePath
	 */
	public function setCookiePath(?string $cookiePath):void
	{
		$this->cookiePath = $cookiePath;
	}

	/**
	 * @param bool $cookieHttpOnly
	 */
	public function setCookieHttpOnly(bool $cookieHttpOnly):void
	{
		$this->cookieHttpOnly = $cookieHttpOnly;
	}

	/**
	 * @return bool
	 */
	public function isCookieHttpOnly():bool
	{
		return $this->cookieHttpOnly;
	}

	/**
	 * @return null|string
	 */
	public function getCookiePath():?string
	{
		return $this->cookiePath;
	}

	/**
	 * @param bool $validateClientIp
	 */
	public function setValidateClientIp(bool $validateClientIp):void
	{
		$this->validateClientIp = $validateClientIp;
	}

	/**
	 * @return bool
	 */
	public function getValidateClientIp():bool
	{
		return $this->validateClientIp;
	}
}