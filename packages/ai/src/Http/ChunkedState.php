<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

/** @internal Where ChunkedDecoder is in the frame it is reading. */
enum ChunkedState
{
    case Size;
    case Data;
    case DataEnd;
    case Trailer;
    case Done;
}
