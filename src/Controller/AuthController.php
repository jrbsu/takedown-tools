<?php

namespace App\Controller;

use App\Entity\User;
use GuzzleHttp\ClientInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\JWTUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use MediaWiki\OAuthClient\Token;
use MediaWiki\OAuthClient\Client;
use Psr\SimpleCache\CacheInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class AuthController {

	/**
	 * @var CacheInterface
	 */
	protected $cache;

	/**
	 * @var ClientInterface
	 */
	protected $client;

	/**
	 * @var Client
	 */
	protected $oauthClient;

	/**
	 * @var TokenStorageInterface
	 */
	protected $tokenStorage;

	/**
	 * @var RegistryInterface
	 */
	protected $doctrine;

	/**
	 * AuthController
	 *
	 * @param CacheInterface $cache PSR Cache Interface
	 * @param ClientInterface $client MediWiki Client.
	 * @param Client $oauthClient MediWiki OAuth Client.
	 * @param TokenStorage $tokenStorage Symfony Token Storage.
	 * @param JWTTokenManagerInterface $jwtManager JWT Manager
	 * @param RegistryInterface $doctrine Doctrine
	 */
	public function __construct(
		CacheInterface $cache,
		ClientInterface $client,
		Client $oauthClient,
		TokenStorage $tokenStorage,
		JWTTokenManagerInterface $jwtManager,
		RegistryInterface $doctrine
	) {
		$this->cache = $cache;
		$this->client = $client;
		$this->oauthClient = $oauthClient;
		$this->tokenStorage = $tokenStorage;
		$this->jwtManager = $jwtManager;
		$this->doctrine = $doctrine;
	}

	/**
	 * Login Action.
	 *
	 * @param Request $request Request object
	 *
	 * @return Response
	 */
	public function loginAction( Request $request ) : Response {
		// If there is no 'oauth_verifier' the user needs to login and give
		// access.
		if ( ! $request->query->has( 'oauth_verifier' ) ) {
			[ $next, $token ] = $this->oauthClient->initiate();

			$this->cache->set( 'requestToken.' . $token->key, $token->secret );

			return new RedirectResponse( $next );
		}

		$key = $request->query->get( 'oauth_token', '' );

		// If the request token is missing from the cache, we need it.
		if ( ! $this->cache->has( 'requestToken.' . $key ) ) {
			return new RedirectResponse( $request->getPathInfo() );
		}

		$secret = $this->cache->get( 'requestToken.' . $key );
		$accessToken = $this->oauthClient->complete(
			new Token( $key, $secret ),
			$request->query->get( 'oauth_verifier' )
		);

		// Clear the requestToken from the cache.
		$this->cache->delete( 'requestToken.' . $key );

		// Get the user's identiy.
		$identiy = $this->oauthClient->identify( $accessToken );

		// Get the userid and add it to the object.
		// @TODO use the MediaWiki API Library
		$response = $this->client->get( '', [
			'query' => [
				'action' => 'query',
				'format' => 'json',
				'list' => 'users',
				'usprop' => 'blockinfo',
				'ususers' => $identiy->username,
			],
		] );

		// This should really be a denormalizer.
		$userdata = json_decode( $response->getBody(), true );

		$id = null;
		if ( ! empty( $userdata['query']['users'] ) ) {
			$id = $userdata['query']['users'][0]['userid'];
		}

		$user = null;
		$em = $this->doctrine->getEntityManager();
		if ( $id ) {
			$user = $em->find( User::class, $id );
		}

		// This should really be a denormalizer.
		if ( $user ) {
			$user->setUsername( $identiy->username );
			$user->setRoles( $this->getRolesFromGroups( $identiy->groups ) );
		} else {
			$user = new User( [
				'id' => $id,
				'username' => $identiy->username,
				'roles' => $this->getRolesFromGroups( $identiy->groups ),
			] );

			$em->persist( $user );
			$em->flush();
		}

		// We cannot use the JWT's returned from MediaWiki becaused they are
		// short-lived tokens.
		$jwt = $this->jwtManager->create( $user );

		// @TODO Redirect the user with JavaScript.
		return new Response( '<script type="text/javascript">'
												 . "localStorage.setItem('token', '$jwt');"
												 . '</script>' );
	}

	/**
	 * Refresh User Token.
	 *
	 * @return Response
	 */
	public function tokenAction() : Response {
			return new JsonResponse( [
				'token' => $this->jwtManager->create( $this->getUser() ),
			] );
	}

 /**
	* Get roles from groups.
	*
	* @param array $groups Groups.
	*
	* @return array
	*/
	protected function getRolesFromGroups( array $groups ) : array {
		$groups = array_filter( $groups, function( $group ) {
			return $group !== "*";
		} );

		$roles = array_map( function( $group ) {
			return 'ROLE_' . strtoupper( $group );
		}, $groups );

		return array_values( $roles );
	}

 /**
	* Get a user from the Security Token Storage.
	*
	* @return User
	*/
	protected function getUser() :? User {
		$token = $this->tokenStorage->getToken();

		if ( $token === null ) {
			return $token;
		}

		$user = $token->getUser();

		if ( ! $user instanceof User ) {
				return null;
		}

		return $user;
	}

}