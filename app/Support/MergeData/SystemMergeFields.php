<?php

namespace App\Support\MergeData;

/**
 * The system merge-field catalog — the `${key}` vocabulary the universal
 * document templates use, ported from bg_hazards_demo's ClientMergeData
 * field map (keys kept verbatim so the existing template library keeps
 * working; only `${EMS_direct_phone}` is normalized to `ems_direct_phone`
 * — Phase M's template translation maps the old token).
 *
 * Deliberately NOT here from the demo:
 *  - computed generation-time values (doc_date, doc_date_my, foot_date,
 *    copy_date, today_date, copyright_owner, initials) — the D2 data
 *    builder computes those at merge time, they are not stored data;
 *  - the demo's numbered aqi_site_* SOURCE columns — the aqi_* keys the
 *    templates actually reference are what's registered (the demo's
 *    column-name mismatch bug dies here).
 *
 * Consumed by merge-fields:seed-system (idempotent updateOrCreate) and,
 * later, site-admin tooling.
 */
class SystemMergeFields
{
    /**
     * @return array<string, array{label: string, type: string, group: string, help?: string}>
     */
    public static function catalog(): array
    {
        return [
            // ---- Agency profile ----------------------------------------
            'agency' => ['label' => 'Agency name', 'type' => 'text', 'group' => 'Agency profile'],
            'agency_short' => ['label' => 'Agency short name', 'type' => 'text', 'group' => 'Agency profile'],
            'client_ident' => ['label' => 'Client identifier', 'type' => 'text', 'group' => 'Agency profile'],
            'agency_pronoun' => ['label' => 'Agency pronoun', 'type' => 'text', 'group' => 'Agency profile', 'help' => 'e.g. "the City", "the District"'],
            'address_1' => ['label' => 'Address line 1', 'type' => 'text', 'group' => 'Agency profile'],
            'address_2' => ['label' => 'Address line 2', 'type' => 'text', 'group' => 'Agency profile'],
            'city' => ['label' => 'City', 'type' => 'text', 'group' => 'Agency profile'],
            'state' => ['label' => 'State', 'type' => 'text', 'group' => 'Agency profile'],
            'zip' => ['label' => 'ZIP', 'type' => 'text', 'group' => 'Agency profile'],
            'agency_county' => ['label' => 'County', 'type' => 'text', 'group' => 'Agency profile'],
            'email' => ['label' => 'Main email', 'type' => 'text', 'group' => 'Agency profile'],
            'url' => ['label' => 'Website', 'type' => 'text', 'group' => 'Agency profile'],
            'phone' => ['label' => 'Main phone', 'type' => 'text', 'group' => 'Agency profile'],

            // ---- Terminology --------------------------------------------
            'policy_term_pref' => ['label' => 'Preferred term for "policy"', 'type' => 'text', 'group' => 'Terminology', 'help' => 'e.g. policy / program / plan'],
            'manager_term' => ['label' => 'Term for managers', 'type' => 'text', 'group' => 'Terminology'],
            'supervisor_term' => ['label' => 'Term for supervisors', 'type' => 'text', 'group' => 'Terminology'],
            'employee_term' => ['label' => 'Term for employees', 'type' => 'text', 'group' => 'Terminology'],
            'hr_dept_term' => ['label' => 'HR department name', 'type' => 'text', 'group' => 'Terminology'],
            'hr_dept_term_short' => ['label' => 'HR department short name', 'type' => 'text', 'group' => 'Terminology'],

            // ---- Managers ------------------------------------------------
            'top_manager' => ['label' => 'Top manager', 'type' => 'text', 'group' => 'Managers'],
            'top_manager_title' => ['label' => 'Top manager title', 'type' => 'text', 'group' => 'Managers'],
            'top_manager_phone' => ['label' => 'Top manager phone', 'type' => 'text', 'group' => 'Managers'],
            'top_manager_email' => ['label' => 'Top manager email', 'type' => 'text', 'group' => 'Managers'],
            'hr_manager' => ['label' => 'HR manager', 'type' => 'text', 'group' => 'Managers'],
            'hr_manager_title' => ['label' => 'HR manager title', 'type' => 'text', 'group' => 'Managers'],
            'hr_manager_phone' => ['label' => 'HR manager phone', 'type' => 'text', 'group' => 'Managers'],
            'hr_manager_email' => ['label' => 'HR manager email', 'type' => 'text', 'group' => 'Managers'],
            'risk_manager' => ['label' => 'Risk manager', 'type' => 'text', 'group' => 'Managers'],
            'risk_manager_title' => ['label' => 'Risk manager title', 'type' => 'text', 'group' => 'Managers'],
            'risk_manager_phone' => ['label' => 'Risk manager phone', 'type' => 'text', 'group' => 'Managers'],
            'risk_manager_email' => ['label' => 'Risk manager email', 'type' => 'text', 'group' => 'Managers'],
            'safety_manager' => ['label' => 'Safety manager', 'type' => 'text', 'group' => 'Managers'],
            'safety_manager_title' => ['label' => 'Safety manager title', 'type' => 'text', 'group' => 'Managers'],
            'safety_manager_phone' => ['label' => 'Safety manager phone', 'type' => 'text', 'group' => 'Managers'],
            'safety_manager_email' => ['label' => 'Safety manager email', 'type' => 'text', 'group' => 'Managers'],

            // ---- Emergency / EAP ------------------------------------------
            'ems_direct_phone' => ['label' => 'EMS direct phone', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'eap_name' => ['label' => 'EAP name', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'eap_info' => ['label' => 'EAP info lines', 'type' => 'list', 'group' => 'Emergency / EAP'],
            'sip_primary_location' => ['label' => 'Shelter-in-place: primary location', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'sip_primary_phone' => ['label' => 'Shelter-in-place: primary phone', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'sip_alt_1_location' => ['label' => 'Shelter-in-place: alt 1 location', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'sip_alt_1_phone' => ['label' => 'Shelter-in-place: alt 1 phone', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'sip_alt_2_location' => ['label' => 'Shelter-in-place: alt 2 location', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'sip_alt_2_phone' => ['label' => 'Shelter-in-place: alt 2 phone', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_name_primary' => ['label' => 'Emergency coordinator (primary)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_title_primary' => ['label' => 'Emergency coordinator title (primary)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_mobile_primary' => ['label' => 'Emergency coordinator mobile (primary)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_name_alt_1' => ['label' => 'Emergency coordinator (alt 1)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_title_alt_1' => ['label' => 'Emergency coordinator title (alt 1)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_mobile_alt_1' => ['label' => 'Emergency coordinator mobile (alt 1)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_name_alt_2' => ['label' => 'Emergency coordinator (alt 2)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_title_alt_2' => ['label' => 'Emergency coordinator title (alt 2)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'emerg_coordinator_mobile_alt_2' => ['label' => 'Emergency coordinator mobile (alt 2)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'assembly_area_onsite_primary' => ['label' => 'Assembly area onsite (primary)', 'type' => 'text', 'group' => 'Emergency / EAP', 'help' => 'Location/department overrides fit here well'],
            'assembly_area_onsite_alt' => ['label' => 'Assembly area onsite (alt)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'assembly_area_offsite_primary' => ['label' => 'Assembly area offsite (primary)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'assembly_area_offsite_alt' => ['label' => 'Assembly area offsite (alt)', 'type' => 'text', 'group' => 'Emergency / EAP'],
            'med_provider_info' => ['label' => 'Medical provider info', 'type' => 'multiline', 'group' => 'Emergency / EAP', 'help' => 'Renders as separate lines in documents'],

            // ---- Air quality (AQI) ----------------------------------------
            'aqi_title_1' => ['label' => 'AQI site 1 title', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_url_1' => ['label' => 'AQI site 1 URL', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_used_for_loc_1' => ['label' => 'AQI site 1 used for', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_title_2' => ['label' => 'AQI site 2 title', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_url_2' => ['label' => 'AQI site 2 URL', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_used_for_loc_2' => ['label' => 'AQI site 2 used for', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_title_3' => ['label' => 'AQI site 3 title', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_url_3' => ['label' => 'AQI site 3 URL', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_used_for_loc_3' => ['label' => 'AQI site 3 used for', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_title_4' => ['label' => 'AQI site 4 title', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_url_4' => ['label' => 'AQI site 4 URL', 'type' => 'text', 'group' => 'Air quality (AQI)'],
            'aqi_used_for_loc_4' => ['label' => 'AQI site 4 used for', 'type' => 'text', 'group' => 'Air quality (AQI)'],

            // ---- Safety data sheets ----------------------------------------
            'online_sds_firm' => ['label' => 'Online SDS provider', 'type' => 'text', 'group' => 'Safety data sheets'],
            'online_sds_url' => ['label' => 'Online SDS URL', 'type' => 'text', 'group' => 'Safety data sheets'],

            // ---- Bloodborne pathogens --------------------------------------
            'bbp_affected_workgroups' => ['label' => 'BBP affected workgroups', 'type' => 'list', 'group' => 'Bloodborne pathogens'],
            'bbp_category_2_workgroups' => ['label' => 'BBP category 2 workgroups', 'type' => 'list', 'group' => 'Bloodborne pathogens'],

            // ---- Confined space --------------------------------------------
            'always_cs_permit_req_spaces' => ['label' => 'Always permit-required spaces', 'type' => 'list', 'group' => 'Confined space'],

            // ---- LOTO --------------------------------------------------------
            'loto_affected_workgroups' => ['label' => 'LOTO affected workgroups', 'type' => 'list', 'group' => 'LOTO'],
            'loto_authorized_workgroups' => ['label' => 'LOTO authorized workgroups', 'type' => 'list', 'group' => 'LOTO'],
            'loto_supply_locations' => ['label' => 'LOTO supply locations', 'type' => 'list', 'group' => 'LOTO'],
            'loto_danger_tag_color' => ['label' => 'LOTO danger tag color', 'type' => 'text', 'group' => 'LOTO'],

            // ---- Contacts & visits -------------------------------------------
            'group_contact_names' => ['label' => 'Group contact names', 'type' => 'text', 'group' => 'Contacts & visits'],
            'group_contact_emails' => ['label' => 'Group contact emails', 'type' => 'text', 'group' => 'Contacts & visits'],
            'file_share_url' => ['label' => 'File share URL', 'type' => 'text', 'group' => 'Contacts & visits'],
            'file_share_password' => ['label' => 'File share password', 'type' => 'text', 'group' => 'Contacts & visits'],
            'proposed_visit_date' => ['label' => 'Proposed visit date', 'type' => 'date', 'group' => 'Contacts & visits'],
        ];
    }
}
