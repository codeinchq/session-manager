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
// Date:     08/03/2018
// Time:     17:07
// Project:  lib-session
//
declare(strict_types = 1);
namespace CodeInc\Session\Middleware;
use CodeInc\Psr15Middlewares\AbstractRecursiveMiddleware;
use CodeInc\Session\Manager\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;


/**
 * Class SessionMiddleware
 *
 * @package CodeInc\Session
 * @author Joan Fabrégat <joan@codeinc.fr>
 */
class SessionMiddleware extends AbstractRecursiveMiddleware {
	public const REQ_ATTR = '__sessionManager';

	/**
	 * @var SessionManagerInstantiatorInterface
	 */
	private $instantiator;

	/**
	 * SessionMiddleware constructor.
	 *
	 * @param null|MiddlewareInterface $nextMiddleware
	 * @param SessionManagerInstantiatorInterface|null $instantiator
	 */
	public function __construct(SessionManagerInstantiatorInterface $instantiator,
		?MiddlewareInterface $nextMiddleware = null)
	{
		parent::__construct($nextMiddleware);
		$this->instantiator = $instantiator;
	}

	/**
	 * @inheritdoc
	 * @throws \CodeInc\Session\Manager\SessionManagerException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler):ResponseInterface
	{
		// starts the session
		$sessionManager = $this->instantiator->instantiate($request);
		$sessionManager->start();

		// processes the response
		$response = parent::process(
			$request->withAttribute(self::REQ_ATTR, $sessionManager),
			$handler
		);

		// if the response is a HTML page, attaches the cookie
		if (preg_match("#^text/html#ui", $response->getHeaderLine("Content-Type"))) {
			$response = $sessionManager->getSessionCookie()->addToResponse($response);
		}

		return $response;
	}

	/**
	 * Detaches the session manager from a server request.
	 *
	 * @param ServerRequestInterface $request
	 * @return SessionManager|null
	 */
	public static function detachSessionManager(ServerRequestInterface $request):?SessionManager
	{
		return $request->getAttribute(self::REQ_ATTR, null);
	}
}