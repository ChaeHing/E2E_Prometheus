<?php

############################################################################################
# E2E 관제 Target 정보를 telco_api로 call 
# prometheus config file을 형식에 맞게 생성 (yaml) -> pecl yaml 사용
# 생성한 config file을 prometheus에 적용하기 위해 reload
# crontab에 등록하여 주기적으로 실행
#* * * * * root php /usr/service/php/e2e_target.php >> /usr/service/php/e2e_target.log 2>&1
############################################################################################

ini_set('memory_limit','-1'); # 메모리 사용량 제한 풀기

$set = parse_ini_file("/usr/service/php/e2e_target.ini", true); #설정 불러오기
$intreval = $set["setting"]["interval"]; # target scrap interval
$telco_api = $set["setting"]["telco_api"]; # telco_api 주소
$blackbox = $set["setting"]["blackbox"];# blackbox 주소 (ip:port)

$arr = json_decode(file_get_contents($telco_api), true); # telco api를 가져오기
$count = 0; # target 갯수 count 변수


##########################
# 모니터링 target 정보 추출
##########################

for ($i = 0; $i < count($arr); $i++) # 도메인정보와 target(서버) 정보 추출하여 배열로 저장
{
        # jobname = 서비스_도메인_서버_timeout #AMS-644 timeout 추가
        $svc[$count] = $arr[$i]["svcName"] . "_" . $arr[$i]["domain"] . "_" . $arr[$i]["svrId"] . "_" . $arr[$i]["timeout"];
        $domain[$count] = $arr[$i]["domain"]; # 도메인
        $timeout[$count] = $arr[$i]["timeout"]; # timeout

        $options[$count] = explode(',', $arr[$i]["requestOptions"]); # 옵션추출을 위해 "," 를 구분하여 저장
        for ($j = 0; $j < count($options[$count]); $j++) {
            $options_explode[$count][$j] = explode(':', $options[$count][$j]);
            # header 옵션 X-Proxy-Stat: no
            # header를 ":" 로 구분하여 배열에 저장 [0] = X-proxy_Stat, [1] = no
        }

        # target url  ex) http://183.111.45.35/images/site/img/module/ajunews/aju_pr/aju_logo_2.png
        $url[$count] = $arr[$i]["scheme"] . "://" . $arr[$i]["ip"] . $arr[$i]["path"];
        $count++; # target 갯수 증가
}

#########################################
# prometheus config 생성 (prometheus.yml)
# array로 생성 한뒤 변환
#########################################

$target = array( # config가 단일로 구성되어있는부분 먼저 저장
    "global" => array(
        "scrape_interval" => $intreval . "s" # interval값 선언
    )
);

# scrape_config 구성, $count만큼 job 생성
for ($i = 0; $i < $count; $i++) {
    $target["scrape_configs"][$i] = array(
        "job_name" => $svc[$i], #  서비스명_도메인_서버명
        "scheme" => "http",
        "metrics_path" => "/probe",
        "params" => array(
            "module" => "[" . $svc[$i] . "]", # 사용할 blackbox module명-> 서비스명_도메인_서버명
        ),
        "static_configs" => array(
            array(
                "targets" => array(
                    $url[$i]
                )
            )
        ),
        "relabel_configs" => array(
            array(
                "source_labels" => "[__address__]",
                "target_label" => "__param_target"
            ),
            array(
                "source_labels" => "[__param_target]",
                "target_label" => "instance"
            ),
            array(
                "target_label" => "__address__",
                "replacement" => $blackbox # blckbox exporter 주소
            )
        )
    );
}


$a = yaml_emit($target); # array를 yaml 형식으로 변환하여 다시 저장
$a = str_replace("'[", "[", $a);
$a = str_replace("]'", "]", $a); # '[값]' 으로 생성되는 것들을 []로 변환

$fp = fopen("/usr/service/prometheus/prometheus.yml", 'w'); # prometheus 설정파일
fwrite($fp, $a); # 변환한 yaml 형식 쓰기
fclose($fp);

#############################################
# blackbox_exporter config 생성 (blackbox.yml)
#############################################

for ($i = 0; $i < $count; $i++) # 도메인의 수만큼 module 생성
{
    $addr["modules"][$svc[$i]] = array( # module명은 서비스명_도메인_서버명
        "prober" => "http",
        "timeout" => "$timeout[$i]s", # timeout
        "http" => array(
            "preferred_ip_protocol" => "ip4", # AMS-641 옵션추가
            "no_follow_redirects" => True, # AMS-641 옵션추가
            "headers" => array(
                "Host" => $domain[$i], #도메인명
            ),
            "tls_config" => array(
                "insecure_skip_verify" => True, # 인증서 무시
                "server_name" => $domain[$i], # 서버별로 https 인증서 만료일 체크시 server_name에 도메인을 명시해야함
            ),
        ),
        "tcp" => array(
            "preferred_ip_protocol" => "ip4", # AMS-641 옵션추가
        ),
        "icmp" => array(
            "preferred_ip_protocol" => "ip4", # AMS-641 옵션추가
        ),
    );

    # 헤더 옵션 추가
    for ($j = 0; $j < count($options[$i]); $j++) {
        # [0] => X-proxy_Stat, [1] => no, trim으로 문자열에 NULL값 삭제
        $addr["modules"][$svc[$i]]["http"]["headers"][trim($options_explode[$i][$j][0])] = trim($options_explode[$i][$j][1]);
        if (trim($options_explode[$i][$j][1]) == "http") # Referer: http://test.solbox.com/ 옵션에 경우 [0] => Referer, [1] =>http, [2] => 주소
        {
            $addr["modules"][$svc[$i]]["http"]["headers"][trim($options_explode[$i][$j][0])] = trim($options_explode[$i][$j][1]) . ":" . trim($options_explode[$i][$j][2]);
            #[1] + [2]로 다시 구성하여 추가
        }

    }

}
$a = yaml_emit($addr); # array를 yaml 형식으로 다시 저장
$a = str_replace('"no"', "no", $a); # "no"를 no로 변환

$fp = fopen("/usr/service/blackbox/blackbox.yml", 'w'); # blackbox 설정파일
fwrite($fp, $a); # 변환한 yaml 형식 쓰기
fclose($fp);

shell_exec("killall -HUP prometheus"); #prometheus 설정 리로드
shell_exec("killall -HUP blackbox_exporter"); #blackbox 설정 리로드


?>