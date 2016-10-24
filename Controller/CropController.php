<?php

/*
 * Copyright (c) 2011-2016 Lp digital system
 *
 * This file is part of BackBee.
 *
 * BackBee is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BackBee is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BackBee. If not, see <http://www.gnu.org/licenses/>.
 */

namespace BackBee\Bundle\CropBundle\Controller;

use BackBee\BBApplication;
use BackBee\Bundle\CropBundle\Utils\Media as CropMedia;
use BackBee\ClassContent\AbstractClassContent;
use BackBee\ClassContent\Element\Image;
use BackBee\ClassContent\Repository\RevisionRepository;
use BackBee\ClassContent\Repository\ClassContentRepository;
use BackBee\ClassContent\Revision;
use BackBee\Exception\InvalidArgumentException;
use BackBee\NestedNode\Media as NestedNodeMedia;
use BackBee\Rest\Controller\AbstractRestController;
use BackBee\Rest\Controller\Annotations as Rest;
use BackBee\Util\Media;
use BackBee\Utils\File\File;
use BackBee\Utils\Exception\InvalidArgumentException as BBInvalidArgumentException;
use BackBee\Utils\Exception\ApplicationException;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CropBundle main controller class
 *
 * @category    BackBee
 * @package     BackBee\Bundle\CropBundle
 * @copyright   Lp digital system
 * @author      Marian Hodis <marian.hodis@lp-digital.fr>
 */
class CropController extends AbstractRestController
{
    /**
     * Request
     *
     * @var Request
     */
    protected $request;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $_em;

    /**
     * Revision repository
     *
     * @var RevisionRepository
     */
    protected $revisionRepository;

    /**
     * ClassContent repository
     *
     * @var ClassContentRepository
     */
    protected $classContentRepository;

    protected $bbtoken;

    /**
     * Crop controller's constructor
     *
     * @param BBApplication $application
     */
    public function __construct(BBApplication $application)
    {
        parent::__construct($application);
        $this->_em = $this->getEntityManager();
        $this->request = $this->getRequest();
        $this->revisionRepository = $this->_em->getRepository('BackBee\ClassContent\Revision');
        $this->classContentRepository = $this->_em->getRepository('BackBee\ClassContent\AbstractClassContent');
        $this->bbtoken = $application->getBBUserToken();
    }

    /**
     * Image crop action
     *
     * @Rest\Security("is_fully_authenticated() & has_role('ROLE_API_USER')")
     *
     * @return boolean $response
     *
     * @throws NotFoundHttpException Occurs if the provided element image is not found
     */
    public function cropAction()
    {
        $imageElement = $this->_em->find('BackBee\ClassContent\Element\Image', $this->request->get('originalUid'));
        $mediaImage = $this->_em->find('BackBee\ClassContent\Media\Image',  $this->request->get('parentUid'));
        $cropAction = $this->request->get('cropAction', null);

        if (null === $imageElement) {
            throw new NotFoundHttpException('Invalid image element');
        }

        if (null === $mediaImage) {
            throw new BadRequestHttpException('Invalid media image');
        }

        if ("new" === $cropAction) {
            $newMediaImage = $this->saveAndNew($imageElement, $mediaImage);
            $newMediaUid = $newMediaImage->getUid();
            $response = $this->createJsonResponse(null, 201, [
                'BB-RESOURCE-UID' =>$newMediaUid,
                'Location'  => $this->getApplication()->getRouting()->getUrlByRouteName(
                    'bb.rest.media.get',
                    [
                        'version' => $this->request->attributes->get('version'),
                        'uid'     => $newMediaUid,
                    ],
                    '',
                    false
                ),
            ]);
        }

        else {
            $this->saveAndReplace($imageElement);
            $mediaUid = $mediaImage->getUid();
            $response = $this->createJsonResponse(null, 204);
        }

        return $response;
    }

    /**
     * Crops the current image element and checkout a new revision
     *
     * @param Image $imageElement
     *
     * @return boolean $response
     */
    protected function saveAndReplace($imageElement)
    {
        return $this->doActualCrop($imageElement, true, null);
    }

    /**
     * Saves a new entry in media library
     *
     * @param Image $imageElement
     *
     * @return boolean $response
     *
     */
    protected function saveAndNew($imageElement, $elementParent=null)
    {
        $title = '';

        try {

            if (null !== $elementParent) {
                // clone the parent
                $newMediaImage = $elementParent->createClone();
                $newMediaImageData = $newMediaImage->getData();
                // get cloned image data
                $clonedImage = $newMediaImageData['image'];
                $title = $newMediaImageData['title']->getData('value');
            }
            else {
                $clonedImage = $imageElement->createClone();
                // create a new media image element and set the cloned image to it
                $mediaImageClass = AbstractClassContent::getClassnameByContentType('Media/Image');
                $newMediaImage = new $mediaImageClass();
                $newMediaImage->__set('image', $clonedImage);
            }

            $this->doActualCrop($clonedImage, false, $imageElement);
            // create the new media element
            $this->createNewMedia($newMediaImage, $title);
            return $newMediaImage;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Do the actual crop of the image
     *
     * @param Image $image
     * @param boolean $generatePathFromClone
     * @param Image $originalImage
     *
     * @return boolean $response
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    protected function doActualCrop(Image $image, $generatePathFromClone = false, Image $originalImage = null)
    {
        $revision = $this->getDraftOrRevision(($originalImage ? $originalImage : $image));

        try {
            $imageData = $this->getImageData($image, $revision->getData('path') ? $revision->getData('path') : $image->getData('path'), $generatePathFromClone);
            // copy a new image which will be cropped
            $this->createDirAndCopyFile($imageData['oldImagePath'], $imageData['newImagePath']);
            // do the actual cropping actions persist the cloned elements
            if (CropMedia::cropImage($imageData['newImagePath'], $this->request->get('cropX'), $this->request->get('cropY'), $this->request->get('cropW'), $this->request->get('cropH'))) {
                $this->updateObject($image, $imageData['newImagePathFromContent'], $imageData['newImagePath']);
                if ($generatePathFromClone) {
                    $this->updateObject($revision, $imageData['newImagePathFromContent'], $imageData['newImagePath']);
                }
                $this->_em->flush();
            }
        } catch (\LogicException $e) {
            throw $e;
        } catch (BBInvalidArgumentException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            throw $e;
        }

        return $image;
    }

    /**
     * Get the new and old image data
     *
     * @param Image $image
     * @param string $imagePath
     * @param boolean $generatePathFromClone
     *
     * @return array
     */
    protected function getImageData(Image $image, $imagePath, $generatePathFromClone)
    {
        $imageData = [];
        if ($generatePathFromClone) {
            $clonedImage = $image->createClone();
            $imageData['newImagePathFromContent'] = Media::getPathFromContent($clonedImage);
            unset($clonedImage);
        } else {
            $imageData['newImagePathFromContent'] = Media::getPathFromContent($image);
        }
        $imageData['oldImagePath'] = $this->application->getMediaDir().DIRECTORY_SEPARATOR.$imagePath;
        $imageData['newImagePath'] = $this->application->getMediaDir().DIRECTORY_SEPARATOR.$imageData['newImagePathFromContent'];
        // in some case Media:getPathFromContent does not return the file extension
        if (!pathinfo($imageData['newImagePath'], PATHINFO_EXTENSION)) {
            $oldExtension = '.' . pathinfo($imageData['oldImagePath'], PATHINFO_EXTENSION);
            $imageData['newImagePath'] .= $oldExtension;
            $imageData['newImagePathFromContent'] .= $oldExtension;
        }

        return $imageData;
    }

    /**
     * If folder is not present create it and then copy the file
     *
     * @param string $oldImagePath The source file path
     * @param string $newImagePath The target file path
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     * @throws ApplicationException
     */
    protected function createDirAndCopyFile($oldImagePath, $newImagePath)
    {
        try {
            if (!is_dir(dirname($newImagePath))) {
                File::mkdir(dirname($newImagePath));
            }
            File::copy($oldImagePath, $newImagePath);
        } catch (InvalidArgumentException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } catch (ApplicationException $e) {
            throw $e;
        }
    }

    /**
     * Create a new media entry
     *
     * @param AbstractClassContent $content
     * @param string $title
     *
     * @return void
     */
    protected function createNewMedia(AbstractClassContent $content, $title = '')
    {
        $newMedia = new NestedNodeMedia();
        $newMedia->setMediaFolder($this->_em->getRepository('BackBee\NestedNode\MediaFolder')->getRoot());
        $newMedia->setContent($content);
        $newMedia->setTitle(trim($title.' ['.$this->request->get('selectedProportion').']'));

        $this->_em->persist($newMedia);
        $this->_em->flush();
    }

    /**
     * Update image with the new data
     *
     * @param AbstractClassContent|Revision $object
     * @param string $path
     * @param string $fullPath
     *
     * @return void
     */
    protected function updateObject(&$object, $path, $fullPath)
    {
        $object->__set('path', $path);
        $object->setParam('width', $this->request->get('cropNewW'));
        $object->setParam('height', $this->request->get('cropNewH'));
        $object->setParam('stat', json_encode(stat($fullPath)));
    }

    /**
     * Get draft or the revision of the image
     *
     * @param Image $image
     * 
     * @return Revision
     */
    protected function getDraftOrRevision(Image $image)
    {
        return $this->_em->getRepository('BackBee\ClassContent\Revision')->getDraft($image, $this->bbtoken, true);
    }
}