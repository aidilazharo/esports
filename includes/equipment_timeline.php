<?php
declare(strict_types=1);
require_once __DIR__.'/equipment_entitlements.php';
require_once __DIR__.'/portal_language.php';

function equipmentTimelineData(PDO $pdo, int $schoolId): array {
    $year=(int)date('Y');
    $stmt=$pdo->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW() THEN 1 ELSE 0 END),0) AS active
        FROM students WHERE school_id=?");
    $stmt->execute([$schoolId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Membership count unavailable.');
    $total=(int)$row['total'];
    $active=(int)$row['active'];
    $eligible=equipmentCurrentYearActiveStudents($pdo,$schoolId);
    return ['year'=>$year,'total'=>$total,'active'=>$active,'non_active'=>max(0,$total-$active),'eligible'=>$eligible];
}

function equipmentTimelineCycle(int $eligible): array {
    $eligible=max(0,$eligible);
    $base=intdiv(max(0,$eligible-1),30)*30;
    $milestones=array_values(array_filter(equipmentMilestonesUpTo($base+30),static fn($m)=>$m['threshold']>$base));
    return ['base'=>$base,'milestones'=>$milestones,'next'=>equipmentNextMilestones($eligible,1)[0]];
}

function equipmentRenderTimeline(PDO $pdo,int $schoolId): void {
    $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $t=static fn($v)=>$esc(portalT($v));
    echo '<link rel="stylesheet" href="/assets/css/equipment-timeline.css?v=1">';
    echo '<section class="card equipment-timeline mb-3" aria-label="'.$t('Equipment Progress Timeline').'"><div class="card-body">';
    echo '<h2 class="h5 mb-3">'.$t('Equipment Progress Timeline').'</h2>';
    try { $d=equipmentTimelineData($pdo,$schoolId); } catch(Throwable $ex) {
        error_log('Equipment timeline unavailable: '.$ex->getMessage());
        echo '<p class="text-secondary mb-0" role="status">'.$t('Membership data temporarily unavailable.').'</p></div></section>';
        return;
    }
    $cycle=equipmentTimelineCycle($d['eligible']);
    $base=$cycle['base']; $next=$cycle['next']; $catalog=equipmentCatalog();
    $progress=max(0,min(30,$d['eligible']-$base));
    echo '<div class="equipment-counts">';
    foreach (['Active Members'=>$d['active'],'Non-Active Members'=>$d['non_active'],'Total Members'=>$d['total'],'Eligible Active Members'=>$d['eligible']] as $label=>$value) {
        echo '<div><span>'.$t($label).'</span><strong>'.number_format($value).'</strong></div>';
    }
    echo '</div><div class="d-flex justify-content-between flex-wrap gap-2 small mt-3 mb-2"><strong>'.$t('Registration Year').' '.$d['year'].'</strong><span>'.number_format($d['eligible']).' / '.($base+30).' '.$t('eligible active members').'</span></div>';
    echo '<div class="progress" role="progressbar" aria-label="'.$t('Equipment cycle progress').'" aria-valuemin="0" aria-valuemax="30" aria-valuenow="'.$progress.'"><div class="progress-bar" style="width:'.($progress/30*100).'%"></div></div>';
    echo '<ol class="equipment-milestones">';
    foreach ($cycle['milestones'] as $m) {
        $threshold=(int)$m['threshold'];
        $state=$threshold<=$d['eligible']?'achieved':($threshold===$next['threshold']?'target':'upcoming');
        $label=match($state){'achieved'=>'Achieved','target'=>'Current Target',default=>'Upcoming'};
        echo '<li class="milestone-'.$state.'"><span class="milestone-number">'.$threshold.'</span><div class="milestone-icons">';
        foreach ($m['items'] as $item) {
            $equipment=$catalog[$item['type']];
            echo '<i class="bi '.$esc($equipment['icon']).'" aria-hidden="true"></i>';
        }
        echo '</div><div class="milestone-name">';
        echo implode(' + ',array_map(static fn($item)=>$t($catalog[$item['type']]['label']),$m['items']));
        echo '</div><span class="milestone-state">'.$t($label).'</span></li>';
    }
    echo '</ol><p class="small mb-1"><strong>'.number_format($next['students_needed']).'</strong> '.$t('Students needed for next milestone').' ('.$t($next['label']).')</p>';
    echo '<p class="small text-secondary mb-1">'.$t('Only active members registered this year count toward equipment eligibility. Non-active members are shown for reference. Eligibility does not confirm delivery.').'</p>';
    echo '<p class="small text-secondary mb-0">'.$t('Updated').' '.$esc(date('d M Y H:i')).' · '.$t('Completed equipment cycles').': '.intdiv($d['eligible'],30).'</p></div></section>';
}
