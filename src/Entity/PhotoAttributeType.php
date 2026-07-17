<?php

declare(strict_types=1);

namespace App\Entity;

enum PhotoAttributeType: string
{
    case ClothingColor = 'clothing_color';
    case ClothingType = 'clothing_type';
    case Scene = 'scene';
    case Bib = 'bib';
}
