<?php
declare(strict_types=1);

function teacherPortalConfig(PDO $pdo, string $key, int $teacherId, int $schoolId): array
{
    $studentOptions = teacherCrudOptions(
        $pdo,
        "SELECT id, CONCAT(student_number,' — ',full_name) AS label FROM students WHERE school_id=? ORDER BY full_name",
        [$schoolId]
    );

    $moduleOptions = teacherCrudOptions(
        $pdo,
        "SELECT id, CONCAT(module_code,' — ',module_name) AS label FROM learning_modules ORDER BY sequence_no"
    );

    $certificateOptions = teacherCrudOptions(
        $pdo,
        "SELECT id, CONCAT(template_name,' — ',recipient_scope) AS label FROM certificate_templates WHERE status='active' ORDER BY template_name"
    );

    $membershipOptions = [
        'pending_registration'=>'Pending Registration',
        'pending_payment'=>'Pending Payment',
        'registered'=>'Registered',
        'pending_activation'=>'Pending Activation',
        'active'=>'Active',
        'inactive'=>'Inactive',
        'cancelled'=>'Cancelled',
    ];

    $studentOwns = static function(PDO $pdo, int $id, int $teacherId, int $schoolId): bool {
        $q=$pdo->prepare('SELECT COUNT(*) FROM students WHERE id=? AND school_id=?');
        $q->execute([$id,$schoolId]);
        return (int)$q->fetchColumn()===1;
    };

    $attendanceOwns = static function(PDO $pdo, int $id, int $teacherId, int $schoolId): bool {
        $q=$pdo->prepare('SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE a.id=? AND st.school_id=?');
        $q->execute([$id,$schoolId]);
        return (int)$q->fetchColumn()===1;
    };

    $progressOwns = static function(PDO $pdo, int $id, int $teacherId, int $schoolId): bool {
        $q=$pdo->prepare('SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE p.id=? AND st.school_id=?');
        $q->execute([$id,$schoolId]);
        return (int)$q->fetchColumn()===1;
    };

    $certificateOwns = static function(PDO $pdo, int $id, int $teacherId, int $schoolId): bool {
        $q=$pdo->prepare(
            "SELECT COUNT(*) FROM issued_certificates ic
             LEFT JOIN students st ON st.id=ic.student_id
             LEFT JOIN teachers t ON t.id=ic.teacher_id
             WHERE ic.id=? AND (st.school_id=? OR t.id=?)"
        );
        $q->execute([$id,$schoolId,$teacherId]);
        return (int)$q->fetchColumn()===1;
    };

    $eventAppOwns = static function(PDO $pdo, int $id, int $teacherId, int $schoolId): bool {
        $q=$pdo->prepare('SELECT COUNT(*) FROM teacher_event_applications WHERE id=? AND teacher_id=? AND school_id=?');
        $q->execute([$id,$teacherId,$schoolId]);
        return (int)$q->fetchColumn()===1;
    };

    $configs = [];

    $configs['students'] = [
        'key'=>'students','title'=>'Students','singular'=>'Student','table'=>'students','pk'=>'id','page'=>'/teacher/students.php',
        'intro'=>'Manage students from your school, including membership status and expiry.',
        'owns'=>$studentOwns,
        'fields'=>[
            'student_number'=>['label'=>'Student Number','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'full_name'=>['label'=>'Full Name','type'=>'text','required'=>true,'col'=>'col-md-8'],
            'identity_number'=>['label'=>'Identity Number','type'=>'text','col'=>'col-md-4'],
            'gender'=>['label'=>'Gender','type'=>'select','options'=>['Male'=>'Male','Female'=>'Female','Other'=>'Other'],'col'=>'col-md-4'],
            'date_of_birth'=>['label'=>'Date of Birth','type'=>'date','col'=>'col-md-4'],
            'class_name'=>['label'=>'Class','type'=>'text','col'=>'col-md-4'],
            'jersey_name'=>['label'=>'Jersey Name','type'=>'text','col'=>'col-md-4'],
            'jersey_number'=>['label'=>'Jersey Number','type'=>'text','col'=>'col-md-4'],
            'phone'=>['label'=>'Phone','type'=>'text','col'=>'col-md-4'],
            'email'=>['label'=>'Email','type'=>'email','col'=>'col-md-4'],
            'membership_status'=>['label'=>'Membership Status','type'=>'select','required'=>true,'options'=>$membershipOptions,'default'=>'pending_payment','col'=>'col-md-4'],
            'membership_next_due_at'=>['label'=>'Membership Expiry','type'=>'datetime-local','col'=>'col-md-6'],
            'account_verified_at'=>['label'=>'Account Verified At','type'=>'datetime-local','col'=>'col-md-6'],
        ],
        'create_defaults'=>function(array $data,int $teacherId,int $schoolId): array {
            $data['school_id']=$schoolId;
            $data['qr_token']=bin2hex(random_bytes(32));
            return $data;
        },
        'before_save'=>function(array $data,bool $isUpdate,?int $id,int $teacherId,int $schoolId): array {
            if(!$isUpdate)$data['school_id']=$schoolId;
            return $data;
        },
        'stats'=>[
            ['label'=>'Total Students','sql'=>'SELECT COUNT(*) FROM students WHERE school_id=?','params'=>['__SCHOOL_ID__'],'icon'=>'bi-people'],
            ['label'=>'Active Students','sql'=>"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-person-check'],
            ['label'=>'Pending Membership','sql'=>"SELECT COUNT(*) FROM students WHERE school_id=? AND membership_status IN ('pending_payment','pending_activation')",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-hourglass-split'],
            ['label'=>'Classes','sql'=>"SELECT COUNT(DISTINCT class_name) FROM students WHERE school_id=? AND class_name IS NOT NULL AND class_name<>''",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-diagram-3'],
        ],
        'list_sql'=>"SELECT id,student_number,full_name,class_name,membership_status,membership_next_due_at,created_at
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

    $configs['attendance'] = [
        'key'=>'attendance','title'=>'Attendance','singular'=>'Attendance Session','table'=>'attendance_sessions','pk'=>'id','page'=>'/teacher/attendance.php',
        'intro'=>'Record and manage attendance for students in your school.',
        'owns'=>$attendanceOwns,
        'fields'=>[
            'student_id'=>['label'=>'Student','type'=>'select','required'=>true,'options'=>$studentOptions,'col'=>'col-md-6'],
            'attendance_month'=>['label'=>'Attendance Month','type'=>'date','required'=>true,'default'=>date('Y-m-01'),'col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['open'=>'Open','completed'=>'Completed','cancelled'=>'Cancelled'],'default'=>'open','col'=>'col-md-3'],
            'check_in_at'=>['label'=>'Check In','type'=>'datetime-local','required'=>true,'default'=>date('Y-m-d\TH:i'),'col'=>'col-md-4'],
            'check_out_at'=>['label'=>'Check Out','type'=>'datetime-local','col'=>'col-md-4'],
            'scan_method'=>['label'=>'Scan Method','type'=>'text','required'=>true,'default'=>'teacher_manual','col'=>'col-md-4'],
            'actual_minutes'=>['label'=>'Actual Minutes','type'=>'number','integer'=>true,'required'=>true,'default'=>0,'min'=>0,'col'=>'col-md-3'],
            'credited_minutes'=>['label'=>'Credited Minutes','type'=>'number','integer'=>true,'required'=>true,'default'=>0,'min'=>0,'col'=>'col-md-3'],
            'notes'=>['label'=>'Notes','type'=>'textarea','rows'=>2,'col'=>'col-md-6'],
        ],
        'create_defaults'=>function(array $data,int $teacherId,int $schoolId) use ($studentOwns): array {
            if(!$studentOwns($GLOBALS['pdo'],(int)$data['student_id'],$teacherId,$schoolId)) throw new RuntimeException('Invalid student.');
            return $data;
        },
        'before_save'=>function(array $data,bool $isUpdate,?int $id,int $teacherId,int $schoolId) use ($studentOwns): array {
            if(!$studentOwns($GLOBALS['pdo'],(int)$data['student_id'],$teacherId,$schoolId)) throw new RuntimeException('Invalid student.');
            return $data;
        },
        'stats'=>[
            ['label'=>'Sessions','sql'=>'SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=?','params'=>['__SCHOOL_ID__'],'icon'=>'bi-clock-history'],
            ['label'=>'Completed','sql'=>"SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? AND a.status='completed'",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-check2-circle'],
            ['label'=>'Open Now','sql'=>"SELECT COUNT(*) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=? AND a.status='open'",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-stopwatch'],
            ['label'=>'Credited Hours','sql'=>"SELECT ROUND(COALESCE(SUM(a.credited_minutes),0)/60,1) FROM attendance_sessions a JOIN students st ON st.id=a.student_id WHERE st.school_id=?",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-hourglass-split'],
        ],
        'list_sql'=>"SELECT a.id,a.student_id,a.attendance_month,a.check_in_at,a.check_out_at,a.credited_minutes,a.status,
                           st.student_number,st.full_name
                    FROM attendance_sessions a JOIN students st ON st.id=a.student_id
                    WHERE st.school_id=? ORDER BY a.check_in_at DESC",
        'list_params'=>['__SCHOOL_ID__'],
        'columns'=>[
            ['key'=>'student_number','label'=>'Student No.'],
            ['key'=>'full_name','label'=>'Student'],
            ['key'=>'attendance_month','label'=>'Month'],
            ['key'=>'check_in_at','label'=>'Check In'],
            ['key'=>'check_out_at','label'=>'Check Out'],
            ['key'=>'credited_minutes','label'=>'Minutes'],
            ['key'=>'status','label'=>'Status'],
        ],
        'order'=>[[3,'desc']],
    ];

    $configs['learning_modules'] = [
        'key'=>'learning_modules','title'=>'Learning Modules','singular'=>'Learning Progress','table'=>'student_module_progress','pk'=>'id','page'=>'/teacher/learning_modules.php',
        'intro'=>'Update module progress and scores for students in your school.',
        'owns'=>$progressOwns,
        'fields'=>[
            'student_id'=>['label'=>'Student','type'=>'select','required'=>true,'options'=>$studentOptions,'col'=>'col-md-5'],
            'module_id'=>['label'=>'Module','type'=>'select','required'=>true,'options'=>$moduleOptions,'col'=>'col-md-5'],
            'progress_status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['not_started'=>'Not Started','in_progress'=>'In Progress','completed'=>'Completed'],'default'=>'not_started','col'=>'col-md-2'],
            'gameplay_score'=>['label'=>'Gameplay Score','type'=>'number','step'=>'0.01','min'=>0,'max'=>100,'col'=>'col-md-3'],
            'quiz_score'=>['label'=>'Quiz Score','type'=>'number','step'=>'0.01','min'=>0,'max'=>100,'col'=>'col-md-3'],
            'development_score'=>['label'=>'Development Score','type'=>'number','step'=>'0.01','min'=>0,'max'=>100,'col'=>'col-md-3'],
            'participation_score'=>['label'=>'Participation Score','type'=>'number','step'=>'0.01','min'=>0,'max'=>100,'col'=>'col-md-3'],
            'attended_on'=>['label'=>'Attended On','type'=>'date','col'=>'col-md-4'],
            'notes'=>['label'=>'Notes','type'=>'textarea','rows'=>2,'col'=>'col-md-8'],
        ],
        'before_save'=>function(array $data,bool $isUpdate,?int $id,int $teacherId,int $schoolId) use ($studentOwns): array {
            if(!$studentOwns($GLOBALS['pdo'],(int)$data['student_id'],$teacherId,$schoolId)) throw new RuntimeException('Invalid student.');
            $scores=array_filter([$data['gameplay_score']??null,$data['quiz_score']??null,$data['development_score']??null,$data['participation_score']??null],fn($v)=>$v!==null);
            $data['total_score']=$scores?array_sum($scores)/count($scores):null;
            $data['started_at']=($data['progress_status']??'not_started')==='not_started'?null:date('Y-m-d H:i:s');
            $data['completed_at']=($data['progress_status']??'')==='completed'?date('Y-m-d H:i:s'):null;
            return $data;
        },
        'stats'=>[
            ['label'=>'Progress Records','sql'=>'SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=?','params'=>['__SCHOOL_ID__'],'icon'=>'bi-journal-text'],
            ['label'=>'Completed','sql'=>"SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=? AND p.progress_status='completed'",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-check2-circle'],
            ['label'=>'In Progress','sql'=>"SELECT COUNT(*) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=? AND p.progress_status='in_progress'",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-play-circle'],
            ['label'=>'Average Score','sql'=>"SELECT ROUND(AVG(p.total_score),1) FROM student_module_progress p JOIN students st ON st.id=p.student_id WHERE st.school_id=?",'params'=>['__SCHOOL_ID__'],'icon'=>'bi-graph-up','format'=>fn($v)=>$v!==''&&$v!==null?$v.'%':'-'],
        ],
        'list_sql'=>"SELECT p.id,p.student_id,p.module_id,p.progress_status,p.total_score,p.attended_on,
                           st.student_number,st.full_name,lm.module_code,lm.module_name
                    FROM student_module_progress p
                    JOIN students st ON st.id=p.student_id
                    JOIN learning_modules lm ON lm.id=p.module_id
                    WHERE st.school_id=? ORDER BY lm.sequence_no,st.full_name",
        'list_params'=>['__SCHOOL_ID__'],
        'columns'=>[
            ['key'=>'student_number','label'=>'Student No.'],
            ['key'=>'full_name','label'=>'Student'],
            ['key'=>'module_code','label'=>'Module'],
            ['key'=>'module_name','label'=>'Module Name'],
            ['key'=>'progress_status','label'=>'Status'],
            ['key'=>'total_score','label'=>'Score','format'=>fn($v)=>$v!==null?number_format((float)$v,2).'%':'-'],
            ['key'=>'attended_on','label'=>'Attended'],
        ],
    ];

    $configs['certificates'] = [
        'key'=>'certificates','title'=>'Certificates','singular'=>'Certificate','table'=>'issued_certificates','pk'=>'id','page'=>'/teacher/certificates.php',
        'intro'=>'Issue and manage certificates for students in your school or for your own teacher account.',
        'owns'=>$certificateOwns,
        'fields'=>[
            'template_id'=>['label'=>'Template','type'=>'select','required'=>true,'options'=>$certificateOptions,'col'=>'col-md-5'],
            'recipient_type'=>['label'=>'Recipient Type','type'=>'select','required'=>true,'options'=>['student'=>'Student','teacher'=>'Teacher'],'default'=>'student','col'=>'col-md-3'],
            'student_id'=>['label'=>'Student','type'=>'select','options'=>$studentOptions,'col'=>'col-md-4'],
            'completion_date'=>['label'=>'Completion Date','type'=>'date','default'=>date('Y-m-d'),'col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['draft'=>'Draft','completed'=>'Completed','revoked'=>'Revoked'],'default'=>'draft','col'=>'col-md-3'],
            'notes'=>['label'=>'Notes','type'=>'textarea','rows'=>2,'col'=>'col-md-6'],
        ],
        'create_defaults'=>function(array $data,int $teacherId,int $schoolId) use ($studentOwns): array {
            if(($data['recipient_type']??'student')==='student'){
                if(empty($data['student_id'])||!$studentOwns($GLOBALS['pdo'],(int)$data['student_id'],$teacherId,$schoolId)) throw new RuntimeException('Select a student from your school.');
                $data['teacher_id']=null;
            }else{
                $data['student_id']=null;
                $data['teacher_id']=$teacherId;
            }
            $data['certificate_number']='CERT-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
            $data['public_token']=bin2hex(random_bytes(32));
            return $data;
        },
        'before_save'=>function(array $data,bool $isUpdate,?int $id,int $teacherId,int $schoolId) use ($studentOwns): array {
            if(($data['recipient_type']??'student')==='student'){
                if(empty($data['student_id'])||!$studentOwns($GLOBALS['pdo'],(int)$data['student_id'],$teacherId,$schoolId)) throw new RuntimeException('Select a student from your school.');
                $data['teacher_id']=null;
            }else{
                $data['student_id']=null;
                $data['teacher_id']=$teacherId;
            }
            return $data;
        },
        'stats'=>[
            ['label'=>'Certificates','sql'=>"SELECT COUNT(*) FROM issued_certificates ic LEFT JOIN students st ON st.id=ic.student_id WHERE st.school_id=? OR ic.teacher_id=?",'params'=>['__SCHOOL_ID__','__TEACHER_ID__'],'icon'=>'bi-award'],
            ['label'=>'Completed','sql'=>"SELECT COUNT(*) FROM issued_certificates ic LEFT JOIN students st ON st.id=ic.student_id WHERE (st.school_id=? OR ic.teacher_id=?) AND ic.status='completed'",'params'=>['__SCHOOL_ID__','__TEACHER_ID__'],'icon'=>'bi-patch-check'],
            ['label'=>'Draft','sql'=>"SELECT COUNT(*) FROM issued_certificates ic LEFT JOIN students st ON st.id=ic.student_id WHERE (st.school_id=? OR ic.teacher_id=?) AND ic.status='draft'",'params'=>['__SCHOOL_ID__','__TEACHER_ID__'],'icon'=>'bi-file-earmark-text'],
            ['label'=>'Revoked','sql'=>"SELECT COUNT(*) FROM issued_certificates ic LEFT JOIN students st ON st.id=ic.student_id WHERE (st.school_id=? OR ic.teacher_id=?) AND ic.status='revoked'",'params'=>['__SCHOOL_ID__','__TEACHER_ID__'],'icon'=>'bi-file-earmark-x'],
        ],
        'list_sql'=>"SELECT ic.id,ic.template_id,ic.recipient_type,ic.student_id,ic.completion_date,ic.status,ic.certificate_number,
                           ct.template_name,COALESCE(st.full_name,t.full_name) AS recipient_name
                    FROM issued_certificates ic
                    JOIN certificate_templates ct ON ct.id=ic.template_id
                    LEFT JOIN students st ON st.id=ic.student_id
                    LEFT JOIN teachers t ON t.id=ic.teacher_id
                    WHERE st.school_id=? OR ic.teacher_id=?
                    ORDER BY ic.created_at DESC",
        'list_params'=>['__SCHOOL_ID__','__TEACHER_ID__'],
        'columns'=>[
            ['key'=>'certificate_number','label'=>'Certificate'],
            ['key'=>'recipient_name','label'=>'Recipient'],
            ['key'=>'recipient_type','label'=>'Type'],
            ['key'=>'template_name','label'=>'Template'],
            ['key'=>'completion_date','label'=>'Date'],
            ['key'=>'status','label'=>'Status'],
        ],
    ];

    foreach (['events'=>'programme','competitions'=>'tournament'] as $pageKey=>$eventType) {
        $eventOptions = teacherCrudOptions(
            $pdo,
            "SELECT id, CONCAT(event_code,' — ',title) AS label
             FROM programme_events
             WHERE event_type=? AND status IN ('open','draft')
             ORDER BY start_at DESC,title",
            [$eventType]
        );

        $configs[$pageKey] = [
            'key'=>$pageKey,
            'title'=>$pageKey==='events'?'Events':'Competitions',
            'singular'=>$pageKey==='events'?'Event Application':'Competition Application',
            'table'=>'teacher_event_applications','pk'=>'id','page'=>'/teacher/'.$pageKey.'.php',
            'intro'=>'Manage your '.$pageKey.' applications.',
            'owns'=>$eventAppOwns,
            'fields'=>[
                'event_id'=>['label'=>$pageKey==='events'?'Event':'Competition','type'=>'select','required'=>true,'options'=>$eventOptions,'col'=>'col-md-6'],
                'status'=>['label'=>'Application Status','type'=>'select','required'=>true,'options'=>[
                    'applied'=>'Applied','approved'=>'Approved','rejected'=>'Rejected','withdrawn'=>'Withdrawn'
                ],'default'=>'applied','col'=>'col-md-3'],
                'notes'=>['label'=>'Notes','type'=>'textarea','rows'=>2,'col'=>'col-md-12'],
            ],
            'create_defaults'=>function(array $data,int $teacherId,int $schoolId) use ($eventType): array {
                $data['teacher_id']=$teacherId;
                $data['school_id']=$schoolId;
                $data['event_type']=$eventType;
                return $data;
            },
            'before_save'=>function(array $data,bool $isUpdate,?int $id,int $teacherId,int $schoolId) use ($eventType): array {
                $data['event_type']=$eventType;
                return $data;
            },
            'stats'=>[
                ['label'=>'Applications','sql'=>'SELECT COUNT(*) FROM teacher_event_applications WHERE teacher_id=? AND event_type=?','params'=>['__TEACHER_ID__',$eventType],'icon'=>$pageKey==='events'?'bi-calendar-event':'bi-trophy'],
                ['label'=>'Approved','sql'=>"SELECT COUNT(*) FROM teacher_event_applications WHERE teacher_id=? AND event_type=? AND status='approved'",'params'=>['__TEACHER_ID__',$eventType],'icon'=>'bi-check2-circle'],
                ['label'=>'Applied','sql'=>"SELECT COUNT(*) FROM teacher_event_applications WHERE teacher_id=? AND event_type=? AND status='applied'",'params'=>['__TEACHER_ID__',$eventType],'icon'=>'bi-hourglass-split'],
                ['label'=>'Open Available','sql'=>'SELECT COUNT(*) FROM programme_events WHERE event_type=? AND status=\'open\'','params'=>[$eventType],'icon'=>'bi-door-open'],
            ],
            'list_sql'=>"SELECT a.id,a.event_id,a.status,a.notes,a.created_at,pe.event_code,pe.title,pe.start_at
                        FROM teacher_event_applications a
                        JOIN programme_events pe ON pe.id=a.event_id
                        WHERE a.teacher_id=? AND a.school_id=? AND a.event_type=?
                        ORDER BY a.created_at DESC",
            'list_params'=>['__TEACHER_ID__','__SCHOOL_ID__',$eventType],
            'columns'=>[
                ['key'=>'event_code','label'=>'Code'],
                ['key'=>'title','label'=>$pageKey==='events'?'Event':'Competition'],
                ['key'=>'start_at','label'=>'Start','format'=>fn($v)=>$v?date('d M Y H:i',strtotime((string)$v)):'-'],
                ['key'=>'status','label'=>'Status'],
                ['key'=>'notes','label'=>'Notes'],
                ['key'=>'created_at','label'=>'Applied'],
            ],
            'order'=>[[5,'desc']],
        ];
    }

    return $configs[$key] ?? throw new InvalidArgumentException('Unknown teacher portal page: '.$key);
}
