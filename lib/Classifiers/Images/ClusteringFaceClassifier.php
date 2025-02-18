<?php
/*
 * Copyright (c) 2021. The Nextcloud Recognize contributors.
 *
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Classifiers\Images;

use OCA\Recognize\BackgroundJobs\ClusterFacesJob;
use OCA\Recognize\Classifiers\Classifier;
use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Db\QueueFile;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\QueueService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class ClusteringFaceClassifier extends Classifier {
	public const IMAGE_TIMEOUT = 120; // seconds
	public const IMAGE_PUREJS_TIMEOUT = 360; // seconds
	public const MIN_FACE_RECOGNITION_SCORE = 0.8;
	public const MODEL_NAME = 'faces';

	private Logger $logger;
	private IConfig $config;
	private FaceDetectionMapper $faceDetections;
	private IUserMountCache $userMountCache;
	private IJobList $jobList;

	public function __construct(Logger $logger, IConfig $config, FaceDetectionMapper $faceDetections, QueueService $queue, IRootFolder $rootFolder, IUserMountCache $userMountCache, IJobList $jobList) {
		parent::__construct($logger, $config, $rootFolder, $queue);
		$this->logger = $logger;
		$this->config = $config;
		$this->faceDetections = $faceDetections;
		$this->userMountCache = $userMountCache;
		$this->jobList = $jobList;
	}

	/**
	 * @param string $user
	 * @param \OCA\Recognize\Db\QueueFile[] $queueFiles
	 * @return void
	 */
	public function classify(array $queueFiles): void {
		if ($this->config->getAppValue('recognize', 'tensorflow.purejs', 'false') === 'true') {
			$timeout = self::IMAGE_PUREJS_TIMEOUT;
		} else {
			$timeout = self::IMAGE_TIMEOUT;
		}

		$usersToCluster = [];
		$classifierProcess = $this->classifyFiles(self::MODEL_NAME, $queueFiles, $timeout);

		foreach ($classifierProcess as $queueFile => $faces) {
			$this->removeExistingFaces($queueFile);
			foreach ($faces as $face) {
				if ($face['score'] < self::MIN_FACE_RECOGNITION_SCORE) {
					continue;
				}
				$faceDetection = new FaceDetection();
				$faceDetection->setX($face['x']);
				$faceDetection->setY($face['y']);
				$faceDetection->setWidth($face['width']);
				$faceDetection->setHeight($face['height']);
				$faceDetection->setVector($face['vector']);
				$faceDetection->setFileId($queueFile->getFileId());

				$mounts = $this->userMountCache->getMountsForRootId($queueFile->getRootId());
				$userIds = array_map(function (ICachedMountInfo $mount) {
					return $mount->getUser()->getUID();
				}, $mounts);

				// Insert face detection for all users with access
				foreach ($userIds as $userId) {
					$faceDetection->setUserId($userId);
					try {
						$this->faceDetections->insert($faceDetection);
					} catch (Exception $e) {
						$this->logger->error('Could not store face detection in database', ['exception' => $e]);
						continue;
					}
					$usersToCluster[] = $userId;
				}
				$this->config->setAppValue('recognize', self::MODEL_NAME.'.status', 'true');
			}
		}

		$usersToCluster = array_unique($usersToCluster);
		foreach ($usersToCluster as $userId) {
			if (!$this->jobList->has(ClusterFacesJob::class, ['userId' => $userId])) {
				$this->jobList->add(ClusterFacesJob::class, ['userId' => $userId]);
			}
		}
	}

	private function removeExistingFaces(QueueFile $queueFile) : void {
		try {
			$existingFaceDetections = $this->faceDetections->findByFileId($queueFile->getFileId());
			foreach ($existingFaceDetections as $existingFaceDetection) {
				try {
					$this->faceDetections->delete($existingFaceDetection);
				} catch (Exception $e) {
					$this->logger->warning('Could not delete existing face detection', ['exception' => $e]);
				}
			}
		} catch (Exception $e) {
			$this->logger->error('Could not query existing face detections of file', ['exception' => $e]);
		}
	}
}
