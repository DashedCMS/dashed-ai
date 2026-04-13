<?php

namespace Dashed\DashedAi\Enums;

enum AiCapability: string
{
    case Text = 'text';
    case Json = 'json';
    case Vision = 'vision';
    case Image = 'image';
    case Embedding = 'embedding';
}
