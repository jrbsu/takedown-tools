<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use GeoSocio\EntityUtils\CreatedTrait;
use GeoSocio\EntityUtils\ParameterBag;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity
 * @ORM\Table(name="file")
  * @ORM\HasLifecycleCallbacks
 */
class File {

	use CreatedTrait;

	/**
	 * @var int
	 *
	 * @ORM\Column(name="file_id", type="integer")
	 * @ORM\Id
	 * @ORM\GeneratedValue
	 */
	private $id;

	/**
	 * @var string
	 *
	 * @ORM\Column(name="path", type="string", length=255)
	 */
	private $path;

	/**
	 * Site
	 *
	 * @param array $data Data to construct the object.
	 */
	public function __construct( array $data = [] ) {
		$params = new ParameterBag( $data );
		$this->id = $params->getInt( 'id' );
		$this->path = $params->getString( 'path' );
	}

	/**
	 * Set Id.
	 *
	 * @param string $id ID
	 *
	 * @return self
	 */
	public function setId( int $id ) {
		$this->id = $id;

		return $this;
	}

	/**
	 * Get Id
	 *
	 * @Groups({"api"})
	 *
	 * @return int
	 */
	public function getId() :? int {
		return $this->id;
	}

	/**
	 * Set Path.
	 *
	 * @param string $path Path
	 *
	 * @return self
	 */
	public function setPath( string $path ) {
		$this->path = $path;

		return $this;
	}

	/**
	 * Get Path
	 *
	 * @Groups({"api"})
	 *
	 * @return string
	 */
	public function getPath() :? string {
		return $this->path;
	}

	/**
	 * Get created
	 *
	 * @Groups({"api"})
	 *
	 * @return \DateTime
	 */
	public function getCreated() :? \DateTimeInterface {
			return $this->created;
	}
}
