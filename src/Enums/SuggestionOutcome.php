<?php

namespace CarlJanzell\FilamentFeedback\Enums;

/**
 * How a suggested reply ended. Only `pending` ones show in the inbox.
 */
enum SuggestionOutcome: string
{
    case Pending = 'pending';
    case SentAsIs = 'sent_as_is';
    case SentEdited = 'sent_edited';
    case Discarded = 'discarded';
    /** A newer suggestion for the same post replaced it. */
    case Superseded = 'superseded';
}
