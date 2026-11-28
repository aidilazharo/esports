<?php
declare(strict_types=1);

function schoolPortalConfig(PDO $pdo, string $key, int $schoolId): array
{
    $membershipOptions = [
        'pending_registration'=>'Pending Registration',
        'pending_payment'=>'Pending Payment',
        'registered'=>'Registered',
        'pending_activation'=>'Pending Activation',
        'active'=>'Active',
        'inactive'=>'Inactive',
        'cancelled'=>'Cancelled',
    ];

    $configs = [];

    $configs['teachers'] = [
        'key'=>'teachers','title'=>'Teachers','singular'=>'Teacher','table'=>'teachers','pk'=>'id','page'=>'/school/teachers.php',
        'intro'=>'Manage teachers registered under this school.',
        'list_first'=>true,
        'reset_verification_on_email_change'=>'email_verified_at',
        'fields'=>[
            'full_name'=>['label'=>'Full Name','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'identity_number'=>['label'=>'Identity Number','type'=>'text','col'=>'col-md-6'],
            'position'=>['label'=>'Position','type'=>'text','col'=>'col-md-4'],
            'phone'=>['label'=>'Phone','type'=>'text','col'=>'col-md-4'],
            'email'=>['label'=>'Email','type'=>'email','col'=>'col-md-4'],
        ],
        'stats'=>[
            ['label'=>'Total Teachers','sql'=>'SELECT COUNT(*) FROM teachers WHERE school_id=?','school_param_count'=>1,'icon'=>'bi-person-badge'],
            ['label'=>'Verified Email','sql'=>'SELECT COUNT(*) FROM teachers WHERE school_id=? AND email_verified_at IS NOT NULL','school_param_count'=>1,'icon'=>'bi-envelope-check'],
            ['label'=>'Registered This Month','sql'=>"SELECT COUNT(*) FROM teachers WHERE school_id=? AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')",'school_param_count'=>1,'icon'=>'bi-calendar-check'],
            ['label'=>'Pending Verification','sql'=>'SELECT COUNT(*) FROM teachers WHERE school_id=? AND email_verified_at IS NULL','school_param_count'=>1,'icon'=>'bi-hourglass-split'],
        ],
        'list_sql'=>"SELECT id,full_name,email,phone,position,email_verified_at,created_at
                    FROM teachers WHERE school_id=? ORDER BY created_at DESC",
        'list_params'=>['__SCHOOL_ID__'],
        'columns'=>[
            ['key'=>'full_name','label'=>'Teacher'],
            ['key'=>'position','label'=>'Position'],
            ['key'=>'phone','label'=>'Phone'],
            ['key'=>'email','label'=>'Email'],
            ['key'=>'email_verified_at','label'=>'Verified','html'=>true,'format'=>fn($v)=>$v?'<span class="badge text-bg-success">Verified</span>':'<span class="badge text-bg-warning">Pending</span>'],
            ['key'=>'created_at','label'=>'Registered','format'=>fn($v)=>$v?date('d M Y',strtotime((string)$v)):'-'],
        ],
        'order'=>[[5,'desc']],
    ];

    $configs['students'] = [
        'key'=>'students','title'=>'Students','singular'=>'Student','table'=>'students','pk'=>'id','page'=>'/school/students.php',
        'intro'=>'Manage students and membership from one page.',
        'list_first'=>true,
        'fields'=>[
            'student_number'=>['label'=>'Student Number','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'full_name'=>['label'=>'Full Name','type'=>'text','required'=>true,'col'=>'col-md-8'],
            'identity_number'=>['label'=>'Identity Number','type'=>'text','col'=>'col-md-4'],
            'gender'=>['label'=>'Gender','type'=>'select','options'=>['Male'=>'Male','Female'=>'Female','Other'=>'Other'],'col'=>'col-md-4'],
            'ethnicity'=>['label'=>'Ethnicity','type'=>'text','col'=>'col-md-4'],
            'date_of_birth'=>['label'=>'Date of Birth','type'=>'date','col'=>'col-md-4'],
            'class_name'=>['label'=>'Class','type'=>'text','col'=>'col-md-4'],
            'jersey_name'=>['label'=>'Jersey Name','type'=>'text','col'=>'col-md-2'],
            'jersey_number'=>['label'=>'Jersey No.','type'=>'text','col'=>'col-md-2'],
            'phone'=>['label'=>'Phone','type'=>'text','col'=>'col-md-4'],
            'email'=>['label'=>'Email','type'=>'email','col'=>'col-md-4'],
        ],
        'create_defaults'=>function(array $data, int $schoolId): array {
            $data['qr_token'] = bin2hex(random_bytes(32));
            $data['membership_status'] = 'pending_payment';
            return $data;
        },
        'stats'=>[
            ['label'=>'Total Students','sql'=>'SELECT COUNT(*) FROM students WHERE school_id=?','school_param_count'=>1,'icon'=>'bi-people'],
            ['label'=>'Active Students','sql'=>"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",'school_param_count'=>1,'icon'=>'bi-person-check'],
            ['label'=>'Pending Membership','sql'=>"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status IN ('pending_payment','pending_activation')",'school_param_count'=>1,'icon'=>'bi-hourglass-split'],
            ['label'=>'Expired / Inactive','sql'=>"SELECT COUNT(*) FROM students WHERE school_id=? AND (membership_status='inactive' OR (membership_next_due_at IS NOT NULL AND membership_next_due_at<NOW()))",'school_param_count'=>1,'icon'=>'bi-person-x'],
        ],
        'list_sql'=>"SELECT id,student_number,full_name,class_name,
                    CASE WHEN membership_status='active' AND membership_next_due_at < NOW() THEN 'expired'
                         WHEN membership_status='active' AND membership_next_due_at IS NULL THEN 'inactive'
                         ELSE membership_status END AS membership_status,membership_next_due_at,created_at
                    FROM students WHERE school_id=? ORDER BY created_at DESC",
        'list_params'=>['__SCHOOL_ID__'],
        'columns'=>[
            ['key'=>'student_number','label'=>'Student No.'],
            ['key'=>'full_name','label'=>'Student'],
            ['key'=>'class_name','label'=>'Class'],
            ['key'=>'membership_status','label'=>'Membership','html'=>true,'format'=>function($v){
                $s=(string)$v;$c=$s==='active'?'success':(in_array($s,['pending_payment','pending_activation'],true)?'warning':'secondary');
                return '<span class="badge text-bg-'.e($c).'">'.e(ucwords(str_replace('_',' ',$s))).'</span>';
            }],
            ['key'=>'membership_next_due_at','label'=>'Expiry','format'=>fn($v)=>$v?date('d M Y',strtotime((string)$v)):'-'],
            ['key'=>'created_at','label'=>'Registered','format'=>fn($v)=>$v?date('d M Y',strtotime((string)$v)):'-'],
        ],
        'order'=>[[5,'desc']],
    ];

    $configs['equipment'] = [
        'key'=>'equipment','title'=>'Equipment','singular'=>'Equipment Record','table'=>'school_equipment_records','pk'=>'id','page'=>'/school/equipment.php',
        'intro'=>'Track equipment received, installed or currently in use by this school.',
        'fields'=>[
            'equipment_type'=>['label'=>'Equipment Type','type'=>'select','required'=>true,'options'=>[
                'PS5'=>'PlayStation 5','Controller'=>'Controller','TV'=>'TV / Display','Workstation'=>'Workstation',
                'Monitor'=>'Monitor','Camera'=>'Camera','Network'=>'Network Equipment','Other'=>'Other'
            ],'col'=>'col-md-4'],
            'item_name'=>['label'=>'Item Name','type'=>'text','required'=>true,'col'=>'col-md-5'],
            'quantity'=>['label'=>'Quantity','type'=>'number','integer'=>true,'required'=>true,'default'=>1,'min'=>1,'col'=>'col-md-3'],
            'serial_number'=>['label'=>'Serial Number / Asset Tag','type'=>'text','col'=>'col-md-4'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>[
                'received'=>'Received','installed'=>'Installed','in_use'=>'In Use','maintenance'=>'Maintenance','retired'=>'Retired'
            ],'default'=>'received','col'=>'col-md-4'],
            'received_at'=>['label'=>'Received At','type'=>'date','col'=>'col-md-4'],
            'notes'=>['label'=>'Notes','type'=>'textarea','rows'=>2,'col'=>'col-12'],
        ],
        'stats'=>[
            ['label'=>'Inventory Records','sql'=>'SELECT COUNT(*) FROM school_equipment_records WHERE school_id=?','school_param_count'=>1,'icon'=>'bi-box-seam'],
            ['label'=>'Total Units','sql'=>'SELECT COALESCE(SUM(quantity),0) FROM school_equipment_records WHERE school_id=?','school_param_count'=>1,'icon'=>'bi-boxes'],
            ['label'=>'PS5 Units','sql'=>"SELECT COALESCE(SUM(quantity),0) FROM school_equipment_records WHERE school_id=? AND equipment_type='PS5'",'school_param_count'=>1,'icon'=>'bi-controller'],
            ['label'=>'Workstations','sql'=>"SELECT COALESCE(SUM(quantity),0) FROM school_equipment_records WHERE school_id=? AND equipment_type='Workstation'",'school_param_count'=>1,'icon'=>'bi-pc-display'],
        ],
        'list_sql'=>"SELECT id,equipment_type,item_name,quantity,serial_number,status,received_at,updated_at
                    FROM school_equipment_records WHERE school_id=? ORDER BY updated_at DESC",
        'list_params'=>['__SCHOOL_ID__'],
        'columns'=>[
            ['key'=>'equipment_type','label'=>'Type'],
            ['key'=>'item_name','label'=>'Item'],
            ['key'=>'quantity','label'=>'Qty'],
            ['key'=>'serial_number','label'=>'Serial / Asset Tag'],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>'<span class="badge text-bg-'.e(in_array((string)$v,['installed','in_use','received'],true)?'success':((string)$v==='maintenance'?'warning':'secondary')).'">'.e(ucwords(str_replace('_',' ',(string)$v))).'</span>'],
            ['key'=>'received_at','label'=>'Received','format'=>fn($v)=>$v?date('d M Y',strtotime((string)$v)):'-'],
        ],
        'order'=>[[5,'desc']],
    ];

    $configs['reports'] = [
        'key'=>'reports','title'=>'Reports','singular'=>'School Report','table'=>'school_reports','pk'=>'id','page'=>'/school/reports.php',
        'intro'=>'Create internal school programme reports and notes.',
        'list_first'=>true,
        'fields'=>[
            'report_code'=>['label'=>'Report Code','type'=>'text','required'=>true,'default'=>'RPT-'.date('Ymd-His'),'col'=>'col-md-3'],
            'title'=>['label'=>'Title','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'report_type'=>['label'=>'Type','type'=>'select','required'=>true,'options'=>[
                'student'=>'Students','attendance'=>'Attendance','learning'=>'Learning','equipment'=>'Equipment',
                'event'=>'Event','competition'=>'Competition','other'=>'Other'
            ],'default'=>'other','col'=>'col-md-3'],
            'period_start'=>['label'=>'Period Start','type'=>'date','col'=>'col-md-3'],
            'period_end'=>['label'=>'Period End','type'=>'date','col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['draft'=>'Draft','final'=>'Final','archived'=>'Archived'],'default'=>'draft','col'=>'col-md-3'],
            'summary'=>['label'=>'Summary','type'=>'textarea','rows'=>3,'col'=>'col-12'],
            'details'=>['label'=>'Details','type'=>'textarea','rows'=>5,'col'=>'col-12'],
        ],
        'stats'=>[
            ['label'=>'Reports','sql'=>'SELECT COUNT(*) FROM school_reports WHERE school_id=?','school_param_count'=>1,'icon'=>'bi-bar-chart-line'],
            ['label'=>'Final Reports','sql'=>"SELECT COUNT(*) FROM school_reports WHERE school_id=? AND status='final'",'school_param_count'=>1,'icon'=>'bi-file-earmark-check'],
            ['label'=>'Draft Reports','sql'=>"SELECT COUNT(*) FROM school_reports WHERE school_id=? AND status='draft'",'school_param_count'=>1,'icon'=>'bi-file-earmark-text'],
            ['label'=>'This Month','sql'=>"SELECT COUNT(*) FROM school_reports WHERE school_id=? AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')",'school_param_count'=>1,'icon'=>'bi-calendar2-check'],
        ],
        'list_sql'=>"SELECT id,report_code,title,report_type,period_start,period_end,status,updated_at
                    FROM school_reports WHERE school_id=? ORDER BY updated_at DESC",
        'list_params'=>['__SCHOOL_ID__'],
        'columns'=>[
            ['key'=>'report_code','label'=>'Code'],
            ['key'=>'title','label'=>'Report'],
            ['key'=>'report_type','label'=>'Type'],
            ['key'=>'period_start','label'=>'From'],
            ['key'=>'period_end','label'=>'To'],
            ['key'=>'status','label'=>'Status'],
            ['key'=>'updated_at','label'=>'Updated'],
        ],
        'order'=>[[6,'desc']],
    ];

    return $configs[$key] ?? throw new InvalidArgumentException('Unknown school portal page: '.$key);
}
