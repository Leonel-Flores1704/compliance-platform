<?php

namespace App\Support;

/**
 * User-facing texts for compliance requirement creation (CMP-15).
 * The frontend uses the same texts in frontend/src/features/requirements/messages.ts.
 */
final class RequirementMessages
{
    public const NAME_REQUIRED = 'Requirement name is required.';

    public const NAME_LENGTH = 'Requirement name must be between 3 and 150 characters.';

    public const DESCRIPTION_REQUIRED = 'Description is required.';

    public const DESCRIPTION_LENGTH = 'Description must be between 10 and 2,000 characters.';

    public const REGULATION_REQUIRED = 'Regulation is required.';

    public const REGULATION_LENGTH = 'Regulation must be between 2 and 150 characters.';

    public const DEADLINE_REQUIRED = 'Deadline is required.';

    public const DEADLINE_INVALID = 'Enter a valid deadline date.';

    public const DOCUMENTS_REQUIRED = 'Add at least one required document.';

    public const DOCUMENT_INCOMPLETE = 'Each required document needs a name and a category.';

    public const DOCUMENT_NAME_LENGTH = 'Required document name must be at most 150 characters.';

    public const RESPONSIBLE_REQUIRED = 'Select a responsible user.';

    public const RESPONSIBLE_INVALID = 'The selected responsible user is not valid.';

    public const STATUS_INVALID = 'Status must be one of: Compliant, Pending, At Risk, Overdue.';

    public const FORBIDDEN = 'You do not have permission to create compliance requirements.';

    public const CREATED = 'Compliance requirement created successfully.';

    public const STATUS_UNCHANGED = 'The requirement already has this status.';

    public const NOT_FOUND = 'Compliance requirement not found.';

    public const STATUS_FORBIDDEN = 'You do not have permission to change compliance status.';

    /**
     * "Status updated to Compliant." (CMP-16).
     */
    public static function statusUpdated(string $status): string
    {
        return 'Status updated to '.(self::STATUSES[$status] ?? $status).'.';
    }

    /**
     * Stored value => label shown to users (BR-004).
     */
    public const STATUSES = [
        'compliant' => 'Compliant',
        'pending' => 'Pending',
        'at_risk' => 'At Risk',
        'overdue' => 'Overdue',
    ];
}
