export type Member = {
    id: number;
    member_number: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string | null;
    mobile_phone: string | null;
    street: string | null;
    postal_code: string | null;
    city: string | null;
    country: string | null;
    birth_date: string | null;
    membership_type: string;
    department_role: string | null;
    club_role: string | null;
    is_honorary: boolean;
    custom_values: Record<string, MemberValue> | null;
    joined_at: string | null;
    left_at: string | null;
    deceased_at: string | null;
};

export type MemberSort =
    | 'member_number'
    | 'name'
    | 'city'
    | 'birth_date'
    | 'membership_type'
    | 'joined_at'
    | 'left_at'
    | `custom_${string}`;

export type MemberFilters = {
    q: string;
    custom: Record<string, string>;
    membership: string;
    department_role: string;
    club_role: string;
    welcome: '' | 'received' | 'missing';
    sort: MemberSort;
    direction: 'asc' | 'desc';
    per_page: number;
};

export type MemberFilterKey =
    | 'q'
    | 'membership'
    | 'department_role'
    | 'club_role'
    | 'welcome';

export type MemberFilterOptions = {
    memberships: string[];
    departmentRoles: string[];
    clubRoles: string[];
};

export type MemberPage = {
    data: Member[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

export type MemberValue = string | number | boolean | null;
export type MemberDetail = Omit<Member, 'custom_values'> & {
    [key: string]: MemberValue;
    gender: string | null;
    sponsor_contribution: string | null;
    iban: string | null;
    mandate_reference: string | null;
    mandate_signed_at: string | null;
    mandate_type: 'recurring' | 'one_off';
    account_holder_first_name: string | null;
    account_holder_last_name: string | null;
    account_holder_street: string | null;
    account_holder_postal_code: string | null;
    account_holder_city: string | null;
    account_holder_country: string | null;
    payment_method: string | null;
    lock_version: number;
    created_at: string;
    updated_at: string;
};
export type MemberField = {
    key: string;
    label: string;
    type:
        | 'text'
        | 'email'
        | 'tel'
        | 'date'
        | 'number'
        | 'decimal'
        | 'select'
        | 'boolean';
    required: boolean;
    options: Record<string, string>;
    activeOptions: Record<string, string>;
    emptyLabel: string;
    readOnly: boolean;
    custom: boolean;
    filterable: boolean;
    showInTable: boolean;
    selfserviceVisible: boolean;
    selfserviceEditable: boolean;
    max: number;
};
export type MemberSection = {
    key: string;
    title: string;
    fields: MemberField[];
};
export type MemberDocument = {
    kind: 'application' | 'sepa';
    submitted_online: boolean;
    created_at: string;
    url: string;
};
export type MemberMandate = {
    id: number;
    submitted_online: boolean;
    mandate_reference: string | null;
    mandate_signed_at: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
    active: boolean;
    created_at: string;
    url: string;
};
export type MemberChange = {
    field_schema: Record<string, MemberField> | null;
    id: number;
    actor_name: string;
    version: number;
    before: Record<string, MemberValue>;
    after: Record<string, MemberValue>;
    changed_fields: string[];
    created_at: string;
};
export type MemberHistory = {
    data: MemberChange[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/** A field offered in the member filter builder; "status" is a fixed choice list. */
export type MemberFilterField = Pick<
    MemberField,
    'key' | 'label' | 'options'
> & {
    type: MemberField['type'] | 'status';
};

/** One filter chosen in the member filter builder; valueTo is used for ranges. */
export type MemberFilter = {
    id: number;
    key: string;
    value: string;
    valueTo: string;
};
