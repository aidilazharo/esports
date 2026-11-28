<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/equipment_entitlements.php';
function verifySchoolRedesign(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: ".$message.PHP_EOL); exit(1); }
}
foreach ([0=>0,10=>0,29=>0,30=>1,59=>1,60=>2,101=>3] as $n=>$expected) {
    verifySchoolRedesign(equipmentEntitlementTotals($n)['ps5']===$expected, "PS5 milestone ".$n);
}
verifySchoolRedesign(equipmentNextMilestones(30,1)[0]['threshold']===31, 'Repeated cycle');
$root=dirname(__DIR__);
$dashboard=file_get_contents($root.'/school/dashboard.php');
verifySchoolRedesign(str_contains($dashboard,'equipmentCurrentYearActiveStudents($pdo, $schoolId)'), 'Current year eligibility');
verifySchoolRedesign(str_contains($dashboard,"equipmentEntitlementTotals("), 'Shared entitlement service');
$config=file_get_contents($root.'/school/_configs.php');
$people=substr($config,0,strpos($config,"\$configs['equipment']"));
foreach (['phone_verified_at','email_verified_at','account_verified_at','membership_status','membership_next_due_at'] as $field) {
    verifySchoolRedesign(!str_contains($people,"'".$field."'=>['label'"), 'No editable system field '.$field);
}
verifySchoolRedesign(substr_count($people,"'list_first'=>true")===2,'List-first people management');
echo "School portal entitlement and form configuration checks passed.".PHP_EOL;
