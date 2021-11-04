<?php

#############################################################################################
# prometheus에서 수집한 메트릭을 api call 
# telegraf를 통해 influxDB에 저장하기 위해 json형식으로 메트릭 파일 생성
# crontab에 등록하여 주기적으로 실행
# * * * * * root php /usr/service/php/e2e_metric.php >> /usr/service/php/e2e_metric.log 2>&1
#############################################################################################

ini_set('memory_limit','-1');

$set = parse_ini_file("/usr/service/php/e2e_metric.ini", true); # 설정파일 읽어오기
$prometheus = $set["setting"]["prometheus"]; # prometheus 주소 저장

date_default_timezone_set('Asia/Seoul'); # timezone을 seoul로 (현재 시간저장)

# prometheus api를 통해 metric (상태코드, 연결시간, 인증서만료일)을 get
$status_code = json_decode(file_get_contents("http://" . $prometheus . "/api/v1/query?query=probe_http_status_code"), true);
$ssl_earliest_cert_expiry = json_decode(file_get_contents("http://" . $prometheus . "/api/v1/query?query=probe_ssl_earliest_cert_expiry"), true);
# AMS-634 total_time 메트릭 변경으로 인하여 duration_second 추가, 기존 duration_second를 http_duration_second으로 변경
$http_duration_second = json_decode(file_get_contents("http://" . $prometheus . "/api/v1/query?query=probe_http_duration_seconds"), true);
$duration_second = json_decode(file_get_contents("http://" . $prometheus . "/api/v1/query?query=probe_duration_seconds"), true);

############################################################
# target 메트릭 매칭                                         
#                                                           
# ** 메트릭 별로 배열이 다르기때문에 같은 target끼리 매칭해야함  
############################################################

# http_code 메트릭배열
for ($i = 0; $i < count($status_code["data"]["result"]); $i++) {# 배열의 count = target 수

    $tag = explode('_', $status_code["data"]["result"][$i]["metric"]["job"]);  # 서비스네임_도메인_서버명_timeout을 구분하여 저장
    # tag[0] = svcname
    # tag[1] = zone
    # tag[2] = svr_id
    # tag[3] = timeout

    $http_code = $status_code["data"]["result"][$i]["value"][1]; # status_code 저장
    $cert_expiry = 999; # 인증서 만료일 초기값, https인 경우만 만료일이 생성되어 defalut값 저장

    # AMS-656
    # 임시 메트릭 배열 생성, $metric[서비스네임_도메인_서버명] (unique한 key) 
    # 초기값 저장, http_code 저장
    $metric["$tag[0]"."_"."$tag[1]"."_"."$tag[2]"] = array("svcname" => $tag[0], "zone" => $tag[1], "svr_id" => $tag[2], "timeout" => $tag[3], "http_code" => $http_code, "cert_expiry" => $cert_expiry);


}

# 인증서만료일 메트릭배열
for ($i = 0; $i < count($ssl_earliest_cert_expiry["data"]["result"]); $i++) {

    $tag = explode('_', $ssl_earliest_cert_expiry["data"]["result"][$i]["metric"]["job"]);  # 서비스네임_도메인_서버명을 구분하여 저장

    $cert_expiry = $ssl_earliest_cert_expiry["data"]["result"][$i]["value"][1]; # 인증서 만료일 저장 (cert_expiry)
    $cert_expiry = date('Y-m-d', $cert_expiry); # unixtimestamp를 날짜로 변환
    $today = date("Y-m-d", time()); # 오늘 날짜

    $cert_expiry = (strtotime($cert_expiry) - strtotime($today)) / 86400; # 남은일수 계산하여 저장
    $cert_expiry = (string)$cert_expiry; # cert_expiry를 문자열 타입으로 변환

    $metric["$tag[0]"."_"."$tag[1]"."_"."$tag[2]"]["cert_expiry"] = $cert_expiry; # cert_expiry 추가

}

# http time 관련 메트릭배열
# AMS-677
# 해당 배열은 metric을 time 종류별로 5개 생성 하기때문에 다른 배열 크기 * 5 (target수 * 5)
for ($i = 0; $i < count($http_duration_second["data"]["result"]); $i++) { 


    $tag = explode('_', $http_duration_second["data"]["result"][$i]["metric"]["job"]);  # 서비스네임_도메인_서버명을 구분하여 저장

    if($http_duration_second["data"]["result"][$i]["metric"]["phase"] == "connect") # 해당 메트릭이 connect time인지 체크
    {
        $connect_time = $http_duration_second["data"]["result"][$i]["value"][1]; # connect time 저장
        $metric["$tag[0]"."_"."$tag[1]"."_"."$tag[2]"]["connect_time"] = $connect_time; # connect time 추가
    }

    if($http_duration_second["data"]["result"][$i]["metric"]["phase"] == "processing") # 해당 메트릭이 1byte time인지 체크
    {
        $first_byte_time = $http_duration_second["data"]["result"][$i]["value"][1]; # 1byte time 저장
        $metric["$tag[0]"."_"."$tag[1]"."_"."$tag[2]"]["first_byte_time"] = $first_byte_time; # 1byte time 추가
    }

}


# total_time 메트릭배열
# total_time 배열은 for문 count가 target 수와 동일 
# 임시 메트릭 배열을 total_metric배열에 저장하기 위해 맨마지막에 처리
for ($i = 0; $i < count($duration_second["data"]["result"]); $i++) {

    $tag = explode('_', $duration_second["data"]["result"][$i]["metric"]["job"]);

    #AMS-634 total_time 변경
    $total_time = $duration_second["data"]["result"][$i]["value"][1]; # total_time값 저장

    $metric["$tag[0]"."_"."$tag[1]"."_"."$tag[2]"]["total_time"] = $total_time; # total_time 추가

    # AMS-656
    # 임시배열을 total_metrics 배열에 저장
    # telegraf와 약속된 json형식으로 저장하기 위해
    $total_metrics[$i] = $metric["$tag[0]"."_"."$tag[1]"."_"."$tag[2]"]; 
}


##################
# json file 생성  
##################

$current_day = date("Ymd", time()); # 현재날짜저장 (디렉토리 생성)
$current_day = (string)$current_day; # current_day 을 문자열 타입으로 변환

$current_time = date("YmdHis", time()); # 현재시간저장 (파일이름에 추가)
$current_time = (string)$current_time; # current_time 을 문자열 타입으로 변환

if (!is_dir("/tmp/prometheus")) { # 해당 디렉토리가 없다면 생성
    shell_exec("mkdir /tmp/prometheus"); # 전체 메트릭 파일 저장 디렉토리
}
if (!is_dir("/tmp/prometheus/$current_day")) { # 해당 디렉토리가 없다면 생성
    shell_exec("mkdir /tmp/prometheus/$current_day"); # 날짜별 디렉토리
}

$total_metrics = json_encode($total_metrics); # 메트릭 배열을 json형식으로 인코딩하여 다시 저장

# 저장용 jsonfile 생성
$fp = fopen("/tmp/prometheus/$current_day/e2e_delivery.json_$current_time", 'w'); # 메트릭 jsonfile 생성
fwrite($fp, $total_metrics); # json으로 변환한 메트릭 내용 쓰기
fclose($fp);

# telegraf에서 실제로 참조하는 jsonfile
# 저장용 jsonfile을 overwrite
shell_exec("/bin/cp /tmp/prometheus/$current_day/e2e_delivery.json_$current_time /tmp/prometheus/e2e_delivery.json");

?>