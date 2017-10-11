<?php
/**
 * @copyright Copyright (c) 2017 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Files\ObjectStore;

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;

const S3_UPLOAD_PART_SIZE = 524288000; // 500MB

trait S3ObjectTrait {
	/**
	 * Returns the connection
	 *
	 * @return S3Client connected client
	 * @throws \Exception if connection could not be made
	 */
	abstract protected function getConnection();

	/**
	 * @param string $urn the unified resource name used to identify the object
	 * @return resource stream with the read data
	 * @throws \Exception when something goes wrong, message will be logged
	 * @since 7.0.0
	 */
	function readObject($urn) {
		$client = $this->getConnection();
		$command = $client->getCommand('GetObject', [
			'Bucket' => $this->bucket,
			'Key' => $urn
		]);
		$command['@http']['stream'] = true;
		$result = $client->execute($command);
		/** @var StreamInterface $body */
		$body = $result['Body'];

		return $body->detach();
	}

	/**
	 * @param string $urn the unified resource name used to identify the object
	 * @param resource $stream stream with the data to write
	 * @throws \Exception when something goes wrong, message will be logged
	 * @since 7.0.0
	 */
	function writeObject($urn, $stream) {
		$stat = fstat($stream);

		if ($stat['size'] && $stat['size'] < S3_UPLOAD_PART_SIZE) {
			$this->singlePartUpload($urn, $stream);
		} else {
			$this->multiPartUpload($urn, $stream);
		}

	}

	protected function singlePartUpload($urn, $stream) {
		$this->getConnection()->putObject([
			'Bucket' => $this->bucket,
			'Key' => $urn,
			'Body' => $stream
		]);
	}

	protected function multiPartUpload($urn, $stream) {
		$uploader = new MultipartUploader($this->getConnection(), $stream, [
			'bucket' => $this->bucket,
			'key' => $urn,
			'part_size' => S3_UPLOAD_PART_SIZE
		]);

		$tries = 0;

		do {
			try {
				$result = $uploader->upload();
			} catch (MultipartUploadException $e) {
				\OC::$server->getLogger()->logException($e);
				rewind($stream);
				$tries++;

				if ($tries < 5) {
					$uploader = new MultipartUploader($this->getConnection(), $stream, [
						'state' => $e->getState()
					]);
				} else {
					$this->getConnection()->abortMultipartUpload($e->getState()->getId());
				}
			}
		} while (!isset($result) && $tries < 5);
	}

	/**
	 * @param string $urn the unified resource name used to identify the object
	 * @return void
	 * @throws \Exception when something goes wrong, message will be logged
	 * @since 7.0.0
	 */
	function deleteObject($urn) {
		$this->getConnection()->deleteObject([
			'Bucket' => $this->bucket,
			'Key' => $urn
		]);
	}
}
