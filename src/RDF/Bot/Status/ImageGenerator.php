<?php
/*
 * This file is part of rdfbot.
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * The unauthorized use of this code outside the boundaries of
 * Opensoft is prohibited.
 */

namespace RDF\Bot\Status;

use Symfony\Component\Filesystem\Filesystem;
use RDF\Bot\RepoManager;
use Imagine\Image\Point;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Color;
use Imagine\Gd\Font;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class ImageGenerator
{
    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var string
     */
    private $font;

    /**
     * @param Filesystem $fs
     * @param string     $fontPath
     */
    public function __construct(Filesystem $fs, $fontPath)
    {
        $this->fs = $fs;
        $this->font = $fontPath;
    }

    public function generate($username, $repo, $status, $sha)
    {
        $text = sprintf('[RDFBot] - %s %s', $status, substr($sha, 0, 6));

        $imagine = new Imagine();

        $size = new Box(200, 23);
        list($backgroundColor, $textColor) = $this->getColorsForStatus($status);
        $color = new Color($backgroundColor);
        $image = $imagine->create($size, $color);
        $image->draw()->text($text, new Font($this->font, 12, new Color($textColor)), new Point(0,0));

        $filepath = sprintf(__DIR__ . '/../../../../web/status/%s', $username);
        $this->fs->mkdir($filepath);
        $image->save($filepath . '/' . $repo . '.png');
    }

    private function getColorsForStatus($status)
    {
        switch ($status) {
            case RepoManager::STATE_SUCCESS:
                $colors = array('#9EE692', '#416334'); // green
                break;
            case RepoManager::STATE_FAILURE:
                $colors = array('#D26911', '#000000'); // black on red
                break;
            case RepoManager::STATE_ERROR:
            default:
                $colors = array('#ECECEC', '#444444');
                break;
        }

        return $colors;
    }
}
