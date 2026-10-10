<?php

namespace Booking\Service;

use Base\Manager\OptionManager;
use Base\Service\AbstractService;

class BookingTypeService extends AbstractService
{

    protected $optionManager;

    protected $typeColorsBuffer;

    public function __construct(OptionManager $optionManager)
    {
        $this->optionManager = $optionManager;
    }

    public function checkTypeColors($typeColors)
    {
        if (! $typeColors) {
            return true;
        }

        $typeColorsArray = $this->parseTypeColors($typeColors);

        return ! empty($typeColorsArray);
    }

    public function checkType($slug)
    {
        $typeColors = $this->getTypeColors();

        if (isset($typeColors[$slug])) {
            return true;
        } else {
            return false;
        }
    }

    public function setTypeColors($typeColors, $locale = null)
    {
        $this->optionManager->set('service.type-values.booking', $typeColors, $locale);
    }

    public function getTypeColors()
    {
        if (! $this->typeColorsBuffer) {
            $this->typeColorsBuffer = $this->parseTypeColors($this->getTypeColorsRaw());
        }

        return $this->typeColorsBuffer;
    }

    public function getTypeColor($slug)
    {
        $typeColorsArray = $this->getTypeColors();

        if ($slug && isset($typeColorsArray[$slug])) {
            return $typeColorsArray[$slug]['color'];
        }

        return null;
    }

    public function getTypeTitle($slug)
    {
        $typeColorsArray = $this->getTypeColors();

        if ($slug && isset($typeColorsArray[$slug])) {
            return $typeColorsArray[$slug]['title'];
        }

        return strtoupper($slug);
    }

    public function getTypeTitles()
    {
        $typeTitles = array();

        $typeColorsArray = $this->getTypeColors();

        foreach ($typeColorsArray as $slug => $typeColorsItem) {
            $typeTitles[$slug] = $typeColorsItem['title'];
        }

        return $typeTitles;
    }

    public function getTypeColorsRaw()
    {
        return $this->optionManager->get('service.type-values.booking', '');
    }

    protected function parseTypeColors($typeColors)
    {
        $typeColorsArray = array();

        $lines = explode("\n", $typeColors);

        foreach ($lines as $line) {

            $lineContent = trim($line);

            if (strlen($lineContent) > 3) {

                preg_match('~^(.*) *(\(.*\))? *(#[a-f0-9]+)?$~Uis', $lineContent, $matches);

                if (isset($matches[1]) && $matches[1]) {
                    $title = trim(stripslashes(strip_tags($matches[1])));
                } else {
                    $title = null;
                }

                if (isset($matches[2]) && $matches[2]) {
                    $slug = $this->slugify(trim(trim($matches[2], '( )')));
                } else {
                    $slug = $this->slugify($title);
                }

                if (isset($matches[3]) && $matches[3]) {
                    $color = trim($matches[3]);
                } else {
                    $color = null;
                }

                if ($title && $slug) {

                    $typeColorsArray[$slug] = array(
                        'title' => $title,
                        'color' => $color,
                    );
                }
            }
        }

        return $typeColorsArray;
    }

    protected function slugify($slug)
    {
        $slug = str_replace(array('Ä', 'ä', 'Ö', 'ö', 'Ü', 'ü', 'ß'), array('Ae', 'ae', 'Oe', 'oe', 'Ue', 'ue', 'ss'), $slug);

        $slug = preg_replace('~[^\\pL\d]+~u', '-', $slug);
        $slug = trim($slug, '-');
        $slug = strtolower($slug);
        $slug = preg_replace('~[^-\w]+~', '', $slug);

        return $slug;
    }

}
