<?php

namespace App\Support;

/**
 * User-facing texts for organization registration (CMP-14).
 * The frontend uses the same texts in frontend/src/features/organizations/messages.ts.
 */
final class OrganizationMessages
{
    public const LEGAL_NAME_REQUIRED = 'Legal name is required.';

    public const LEGAL_NAME_LENGTH = 'Legal name must be between 3 and 150 characters.';

    public const LEGAL_NAME_TAKEN = 'An organization with this legal name already exists.';

    public const ORGANIZATION_TYPE_REQUIRED = 'Select an organization type.';

    public const ORGANIZATION_TYPE_INVALID = 'The selected organization type is not valid.';

    public const INDUSTRY_REQUIRED = 'Select an industry.';

    public const INDUSTRY_INVALID = 'The selected industry is not valid.';

    public const STATE_REQUIRED = 'Select a state.';

    public const MUNICIPALITY_REQUIRED = 'Municipality is required.';

    public const MUNICIPALITY_LENGTH = 'Municipality must be between 2 and 100 characters.';

    public const EMPLOYEES_REQUIRED = 'Number of employees is required.';

    public const EMPLOYEES_INVALID = 'Number of employees must be a whole number between 1 and 1,000,000.';

    public const ACTIVITIES_REQUIRED = 'Select at least one business activity.';

    public const ACTIVITIES_MAX = 'You can select up to 10 business activities.';

    public const ACTIVITIES_INVALID = 'The selected business activity is not valid.';

    public const FORBIDDEN = 'You do not have permission to register organizations.';

    public const CREATED = 'Organization registered successfully.';
}
