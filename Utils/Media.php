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

namespace BackBee\Bundle\CropBundle\Utils;

use BackBee\Util\MimeType;

/**
 * Set of utility methods to deal with media files
 *
 * @category    BackBee
 * @package     BackBee\Bundle\CropBundle
 * @copyright   Lp digital system
 * @author      Marian Hodis <marian.hodis@lp-digital.fr>
 */
class Media
{
    /**
     * Crop $source
     *
     * @param string $source                The filepath of the source image
     * @param int $xStartCoord              The x coordinate from where to start the crop
     * @param int $yStartCoord              The y coordinate from where to start the crop
     * @param int $cropWidth                The width of the new image
     * @param int $cropHeight               The height of the new image
     *
     * @return boolean $response            Returns TRUE on success, FALSE on failure
     * 
     * @throws \LogicException              Occurs if gd extension is not loaded
     * @throws \InvalidArgumentException    Occurs on unsupported file type or unreadable file source
     */
    public static function cropImage($source, $xStartCoord, $yStartCoord, $cropWidth, $cropHeight)
    {
        if (false === extension_loaded('gd')) {
            throw new \LogicException('gd extension is required');
        }

        if (false === is_readable($source)) {
            throw new \InvalidArgumentException('Enable to read source file');
        }

        $targetImage = imagecreatetruecolor($cropWidth, $cropHeight);
        $mimeType = MimeType::getInstance()->guess($source);

        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($source);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($source);
                break;
            default:
                throw new \InvalidArgumentException('Unsupported picture type');
        }
        imagecopy($targetImage, $sourceImage, 0, 0, $xStartCoord, $yStartCoord, $cropWidth, $cropHeight);
        switch ($mimeType) {
            case 'image/jpeg':
                $response = imagejpeg($targetImage, $source);
                break;
            case 'image/png':
                $response = imagepng($targetImage, $source);
                break;
            case 'image/gif':
                $response = imagegif($targetImage, $source);
                break;
        }

        return $response;
    }
}