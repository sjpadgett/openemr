<?php

/**
 * assessment_intake save.php.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

if ($encounter == "") {
    $encounter = date("Ymd");
}

if ($_GET["mode"] == "new") {
    $newid = formSubmit("form_assessment_intake", $_POST, $_GET["id"], $userauthorized);
    print 'formSubmitt';  /*debugging */
    addForm($encounter, "Assessment and Intake", $newid, "assessment_intake", $pid, $userauthorized);
} elseif ($_GET["mode"] == "update") {
    sqlStatement(
        "update form_assessment_intake set pid = ?,
        groupname=?,
        user=?,
        authorized=?, activity=1, date = NOW(),
        dcn=?,
        location=?,
        time_in=?,
        time_out=?,
        referral_source=?,
        new_client_eval=?,
        readmission=?,
        consultation=?,
        copy_sent_to=?,
        reason_why=?,
        behavior_led_to=?,
        school_work=?,
        personal_relationships=?,
        fatherc=?,
        motherc=?,
        father_involved=?,
        mother_involved=?,
        number_children=?,
        siblings=?,
        other_relationships=?,
        social_relationships=?,
        current_symptoms=?,
        personal_strengths=?,
        spiritual=?,
        legal=?,
        prior_history=?,
        number_admitt=?,
        type_admitt=?,
        substance_use=?,
        substance_abuse=?,
        axis1=?,
        axis2=?,
        axis3=?,
        allergies=?,
        ax4_prob_support_group=?,
        ax4_prob_soc_env=?,
        ax4_educational_prob=?,
        ax4_occ_prob=?,
        ax4_housing=?,
        ax4_economic=?,
        ax4_access_hc=?,
        ax4_legal=?,
        ax4_other_cb=?,
        ax4_other=?,
        ax5_current=?,
        ax5_past=?,
        risk_suicide_na=?,
        risk_suicide_nk=?,
        risk_suicide_io=?,
        risk_suicide_plan=?,
        risk_suicide_iwom=?,
        risk_suicide_iwm=?,
        risk_homocide_na=?,
        risk_homocide_nk=?,
        risk_homocide_io=?,
        risk_homocide_plan=?,
        risk_homocide_iwom=?,
        risk_homocide_iwm=?,
        risk_compliance_na=?,
        risk_compliance_fc=?,
        risk_compliance_mc=?,
        risk_compliance_moc=?,
        risk_compliance_var=?,
        risk_compliance_no=?,
        risk_substance_na =?,
        risk_substance_none=?,
        risk_normal_use=?,
        risk_substance_ou=?,
        risk_substance_dp=?,
        risk_substance_ur=?,
        risk_substance_ab=?,
        risk_sexual_na=?,
        risk_sexual_y=?,
        risk_sexual_n=?,
        risk_sexual_ry=?,
        risk_sexual_rn=?,
        risk_sexual_cv=?,
        risk_sexual_cp=?,
        risk_sexual_b=?,
        risk_sexual_nf=?,
        risk_neglect_na=?,
        risk_neglect_y=?,
        risk_neglect_n=?,
        risk_neglect_ry=?,
        risk_neglect_rn=?,
        risk_neglect_cv=?,
        risk_neglect_cp=?,
        risk_neglect_cb=?,
        risk_neglect_cn=?,
        risk_exists_c=?,
        risk_exists_cn=?,
        risk_exists_s=?,
        risk_exists_o=?,
        risk_exists_b=?,
        risk_community=?,
        recommendations_psy_i=?,
        recommendations_psy_f=?,
        recommendations_psy_m=?,
        recommendations_psy_o=?,
        recommendations_psy_notes=?,
        refer_date=?,
        parent =?,
        supervision_level=?,
        supervision_type=?,
        supervision_res=?,
        supervision_services=?,
        support_ps=?,
        support_cs=?,
        support_sm=?,
        support_a=?,
        support_o=?,
        support_ol=?,
        legal_op=?,
        legal_so=?,
        legal_sa=?,
        legal_ve=?,
        legal_ad=?,
        legal_adl=?,
        legal_o=?,
        legal_ol=?,
        referrals_pepm=?,
        referrals_mc=?,
        referrals_vt=?,
        referrals_o=?,
        referrals_cu=?,
        referrals_docs=?,
        referrals_or=? where
        id=?",
        array(
            $_SESSION["pid"],
            $_SESSION["authProvider"],
            $_SESSION["authUser"],
            $userauthorized,
            $_POST["dcn"],
            $_POST["location"],
            $_POST["time_in"],
            $_POST["time_out"],
            $_POST["referral_source"],
            $_POST["new_client_eval"],
            $_POST["readmission"],
            $_POST["consultation"],
            $_POST["copy_sent_to"],
            $_POST["reason_why"],
            $_POST["behavior_led_to"],
            $_POST["school_work"],
            $_POST["personal_relationships"],
            $_POST["fatherc"],
            $_POST["motherc"],
            $_POST["father_involved"],
            $_POST["mother_involved"],
            $_POST["number_children"],
            $_POST["siblings"],
            $_POST["other_relationships"],
            $_POST["social_relationships"],
            $_POST["current_symptoms"],
            $_POST["personal_strengths"],
            $_POST["spiritual"],
            $_POST["legal"],
            $_POST["prior_history"],
            $_POST["number_admitt"],
            $_POST["type_admitt"],
            $_POST["substance_use"],
            $_POST["substance_abuse"],
            $_POST["axis1"],
            $_POST["axis2"],
            $_POST["axis3"],
            $_POST["allergies"],
            $_POST["ax4_prob_support_group"],
            $_POST["ax4_prob_soc_env"],
            $_POST["ax4_educational_prob"],
            $_POST["ax4_occ_prob"],
            $_POST["ax4_housing"],
            $_POST["ax4_economic"],
            $_POST["ax4_access_hc"],
            $_POST["ax4_legal"],
            $_POST["ax4_other_cb"],
            $_POST["ax4_other"],
            $_POST["ax5_current"],
            $_POST["ax5_past"],
            $_POST["risk_suicide_na"],
            $_POST["risk_suicide_nk"],
            $_POST["risk_suicide_io"],
            $_POST["risk_suicide_plan"],
            $_POST["risk_suicide_iwom"],
            $_POST["risk_suicide_iwm"],
            $_POST["risk_homocide_na"],
            $_POST["risk_homocide_nk"],
            $_POST["risk_homocide_io"],
            $_POST["risk_homocide_plan"],
            $_POST["risk_homocide_iwom"],
            $_POST["risk_homocide_iwm"],
            $_POST["risk_compliance_na"],
            $_POST["risk_compliance_fc"],
            $_POST["risk_compliance_mc"],
            $_POST["risk_compliance_moc"],
            $_POST["risk_compliance_var"],
            $_POST["risk_compliance_no"],
            $_POST["risk_substance_na"],
            $_POST["risk_substance_none"],
            $_POST["risk_normal_use"],
            $_POST["risk_substance_ou"],
            $_POST["risk_substance_dp"],
            $_POST["risk_substance_ur"],
            $_POST["risk_substance_ab"],
            $_POST["risk_sexual_na"],
            $_POST["risk_sexual_y"],
            $_POST["risk_sexual_n"],
            $_POST["risk_sexual_ry"],
            $_POST["risk_sexual_rn"],
            $_POST["risk_sexual_cv"],
            $_POST["risk_sexual_cp"],
            $_POST["risk_sexual_b"],
            $_POST["risk_sexual_nf"],
            $_POST["risk_neglect_na"],
            $_POST["risk_neglect_y"],
            $_POST["risk_neglect_n"],
            $_POST["risk_neglect_ry"],
            $_POST["risk_neglect_rn"],
            $_POST["risk_neglect_cv"],
            $_POST["risk_neglect_cp"],
            $_POST["risk_neglect_cb"],
            $_POST["risk_neglect_cn"],
            $_POST["risk_exists_c"],
            $_POST["risk_exists_cn"],
            $_POST["risk_exists_s"],
            $_POST["risk_exists_o"],
            $_POST["risk_exists_b"],
            $_POST["risk_community"],
            $_POST["recommendations_psy_i"],
            $_POST["recommendations_psy_f"],
            $_POST["recommendations_psy_m"],
            $_POST["recommendations_psy_o"],
            $_POST["recommendations_psy_notes"],
            $_POST["refer_date"],
            $_POST["parent"],
            $_POST["supervision_level"],
            $_POST["supervision_type"],
            $_POST["supervision_res"],
            $_POST["supervision_services"],
            $_POST["support_ps"],
            $_POST["support_cs"],
            $_POST["support_sm"],
            $_POST["support_a"],
            $_POST["support_o"],
            $_POST["support_ol"],
            $_POST["legal_op"],
            $_POST["legal_so"],
            $_POST["legal_sa"],
            $_POST["legal_ve"],
            $_POST["legal_ad"],
            $_POST["legal_adl"],
            $_POST["legal_o"],
            $_POST["legal_ol"],
            $_POST["referrals_pepm"],
            $_POST["referrals_mc"],
            $_POST["referrals_vt"],
            $_POST["referrals_o"],
            $_POST["referrals_cu"],
            $_POST["referrals_docs"],
            $_POST["referrals_or"],
            $id
        )
    );
}

formHeader("Redirecting....");
formJump();
formFooter();
