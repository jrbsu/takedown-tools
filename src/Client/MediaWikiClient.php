<?php

namespace App\Client;

use App\Entity\Site;
use App\Entity\User;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use function GuzzleHttp\Promise\settle;

class MediaWikiClient implements MediaWikiClientInterface {

	/**
	 * @var ClientInterface
	 */
	protected $client;

	/**
	 * @var SerializerInterface
	 */
	protected $serializer;

	/**
	 * @var DenormalizerInterface
	 */
	protected $denormalizer;

	/**
	 * @var DecoderInterface
	 */
	protected $decoder;

	/**
	 * @var string
	 */
	protected $environment;

	/**
	 * MediaWiki
	 *
	 * @param ClientInterface $client Configured Guzzle Client.
	 * @param SerializerInterface $serializer Syfmony Serializer.
	 * @param DenormalizerInterface $denormalizer Syfmony Denormalizer.
	 * @param DecoderInterface $decoder Syfmony Decoder.
	 * @param string $environment Kernel Environment.
	 */
	public function __construct(
		ClientInterface $client,
		SerializerInterface $serializer,
		DenormalizerInterface $denormalizer,
		DecoderInterface $decoder,
		string $environment
	) {
		$this->client = $client;
		$this->serializer = $serializer;
		$this->decoder = $decoder;
		$this->environment = $environment;
	}

	/**
	 * Get Site by Id
	 *
	 * @param string $id Site to retrieve
	 *
	 * @return Site
	 */
	public function getSiteById( string $id ) : PromiseInterface {
		return $this->getSites()->then( function ( $sites ) use ( $id ) {
			$item = reset( $sites );
			while ( $item !== false ) {
					if ( $item->getId() === $id ) {
							return $item;
					};

					$item = next( $sites );
			}

			return null;
		} );
	}

	/**
	 * Get All Sites.
	 *
	 * @return PromiseInterface
	 */
	public function getSites() : PromiseInterface {
		$request = new Request( 'GET', '' );

		return $this->client->sendAsync( $request, [
			'query' => [
				'action' => 'sitematrix',
				'format' => 'json',
			],
		] )->then( function( ResponseInterface $response ) use ( $request ) {
			$data = $this->decoder->decode( (string)$response->getBody(), 'json' );

			if ( array_key_exists( 'error', $data ) ) {
				throw new BadResponseException( $data['error']['info'], $request, $response, null, $data );
			}

			return $this->denormalizer->denormalize(
				$data,
				Site::class . '[]',
				'json'
			);
		} );
	}

	/**
	 * Post notice to Commons.
	 *
	 * @param string $text Text to Post.
	 *
	 * @return array
	 */
	public function postCommons( string $text ) : PromiseInterface {
		$domain = 'test2.wikipedia.org';
		$title = 'Office_actions/DMCA_notices';

		if ( $this->environment === 'prod' ) {
			$domain = 'commons.wikimedia.org';
			$title = 'Commons:Office_actions/DMCA_notices';
		}

		$url = 'https://' . $domain . '/w/api.php';
		$request = new Request( 'POST', $url );

		return $this->getToken( $url )->then( function ( $token ) use ( $request, $title, $text ) {
			return $this->client->sendAsync( $request, [
				'query' => [
					'action' => 'edit',
					'format' => 'json',
				],
				'form_params' => [
					'title' => $title,
					'summary' => 'new takedown',
					'appendtext' => $text,
					'recreate' => true,
					// Tokens are required.
					// @link https://phabricator.wikimedia.org/T126257
					'token' => $token,
				],
				'auth' => 'oauth',
			] );
		} )
		->then( function( $response ) use ( $request, $domain ) {
			$data = $this->decoder->decode( (string)$response->getBody(), 'json' );

			// Override for testing.
			// @TODO Remove this!
			$data = [
				'edit' => [
					'captcha' => [
							'type' => 'image',
							'mime' => 'image/png',
							'id' => '1221847824',
							'url' => '/w/index.php?title=Special:Captcha/image&wpCaptchaId=1221847824'
						],
						'result' => 'Failure'
				],
			];

			if ( array_key_exists( 'error', $data ) ) {
				throw new BadResponseException( $data['error']['info'], $request, $response, null, $data );
			}

			// Handle Captcha Responses.
			if ( array_key_exists( 'edit', $data ) && array_key_exists( 'captcha', $data['edit'] ) ) {
				// Change the Captcha to an absolute url.
				$data['edit']['captcha']['url'] = 'https://' . $domain . $data['edit']['captcha']['url'];
				throw new ClientException( $data['edit']['result'], $request, $response, null, $data['edit'] );
			}

			return $data['edit'];
		} );
	}

	/**
	 * Post notice to Commons Village Pump.
	 *
	 * @param string $text Text to Post.
	 *
	 * @return array
	 */
	public function postCommonsVillagePump( string $text ) : PromiseInterface {
		$domain = 'test2.wikipedia.org';
		$title = 'Wikipedia:Simple_talk';

		if ( $this->environment === 'prod' ) {
			$domain = 'commons.wikimedia.org';
			$title = 'Commons:Village_pump';
		}

		$url = 'https://' . $domain . '/w/api.php';
		$request = new Request( 'POST', $url );

		return $this->getToken( $url )->then( function ( $token ) use ( $request, $title, $text ) {
			return $this->client->sendAsync( $request, [
				'query' => [
					'action' => 'edit',
					'format' => 'json',
				],
				'form_params' => [
					'title' => $title,
					'summary' => 'new DMCA takedown notifcation',
					'appendtext' => $text,
					'recreate' => true,
					// Tokens are required.
					// @link https://phabricator.wikimedia.org/T126257
					'token' => $token,
				],
				'auth' => 'oauth',
			] );
		} )
		->then( function( $response ) use ( $request, $domain ) {
			$data = $this->decoder->decode( (string)$response->getBody(), 'json' );

			if ( array_key_exists( 'error', $data ) ) {
				throw new BadResponseException( $data['error']['info'], $request, $response, null, $data );
			}

			// Handle Captcha Responses.
			if ( array_key_exists( 'edit', $data ) && array_key_exists( 'captcha', $data['edit'] ) ) {
				// Change the Captcha to an absolute url.
				$data['edit']['captcha']['url'] = 'https://' . $domain . $data['edit']['captcha']['url'];
				throw new ClientException( $data['edit']['result'], $request, $response, null, $data['edit'] );
			}

			return $data['edit'];
		} );
	}

	/**
	 * Post to User Talk Page.
	 *
	 * @param Site $site Site
	 * @param User $user User
	 *
	 * @return PromiseInterface
	 */
	public function postUserTalk( Site $site, User $user ) : PromiseInterface {
		$domain = 'test2.wikipedia.org';
		$title = 'User_talk:' . str_replace( ' ', '_', $user->getUsername() );

		if ( $this->environment === 'prod' ) {
			$domain = $site->getDomain();
		}

		$url = 'https://' . $domain . '/w/api.php';
		$request = new Request( 'POST', $url );

		return $this->getToken( $url )->then( function ( $token ) use ( $request, $title, $user ) {
			return $this->client->sendAsync( $request, [
				'query' => [
					'action' => 'edit',
					'format' => 'json',
				],
				'form_params' => [
					'title' => $title,
					'sectiontitle' => 'Notice of upload removal',
					'section' => 'new',
					'summary' => 'Notice of upload removal',
					'text' => $user->getNotice(),
					'recreate' => true,
					// Tokens are required.
					// @link https://phabricator.wikimedia.org/T126257
					'token' => $token,
				],
				'auth' => 'oauth',
			] );
		} )
		->then( function( $response ) use ( $request, $domain ) {
			$data = $this->decoder->decode( (string)$response->getBody(), 'json' );

			if ( array_key_exists( 'error', $data ) ) {
				throw new BadResponseException( $data['error']['info'], $request, $response, null, $data );
			}

			// Handle Captcha Responses.
			if ( array_key_exists( 'edit', $data ) && array_key_exists( 'captcha', $data['edit'] ) ) {
				// Change the Captcha to an absolute url.
				$data['edit']['captcha']['url'] = 'https://' . $domain . $data['edit']['captcha']['url'];
				throw new ClientException( $data['edit']['result'], $request, $response, null, $data['edit'] );
			}

			return $data;
		} );
	}

	/**
	 * Get Token.
	 *
	 * @param string $uri URI
	 *
	 * @return string
	 */
	protected function getToken( $uri = '' ) : PromiseInterface {
		$request = new Request( 'GET', $uri );
		return $this->client->sendAsync( $request, [
			'query' => [
				'action' => 'tokens',
				'format' => 'json',
			],
			'auth' => 'oauth',
		] )->then( function( $response ) use ( $request ) {
			$data = $this->decoder->decode( (string)$response->getBody(), 'json' );

			if ( array_key_exists( 'error', $data ) ) {
				throw new BadResponseException( $data['error']['info'], $request, $response, null, $data );
			}

			return !empty( $data['tokens']['edittoken'] ) ? $data['tokens']['edittoken'] : null;
		} );
	}

	/**
	 * Get Users by Usernames
	 *
	 * @param string $usernames Users to retrieve.
	 *
	 * @return PromiseInterface
	 */
	public function getUsers( array $usernames ) : PromiseInterface {
		$promises = array_map( function( $username ) {
			return $this->getUser( $username );
		}, $usernames );

		return settle( $promises )->then( function( $results ) {
			$results = array_filter( $results, function ( $result ) {
				return $result['state'] === PromiseInterface::FULFILLED;
			} );

			return array_map( function( $result ) {
				return $result['value'];
			}, $results );
		} );
	}

	/**
	 * Get User by Username
	 *
	 * @param string $username Users to retrieve.
	 *
	 * @return PromiseInterface
	 */
	public function getUser( string $username ) : PromiseInterface {
		return $this->client->getAsync( '', [
			'query' => [
				'action' => 'query',
				'format' => 'json',
				'meta' => 'globaluserinfo',
				'guiuser' => $username,
			],
		] )->then( function( $response ) {
			return $this->serializer->deserialize( (string)$response->getBody(), User::class, 'json' );
		}, function ( $e ) {
			return null;
		} );
	}
}
