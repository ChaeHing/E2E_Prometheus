# E2E_Prometheus

1. 구조도 - base.jpg                          
 
 1)	telco api를 Polling하여 target 정보를 생성
 2)	prometheus.yml, blackbox.yml을 생성하여 각각 배포 
 3)	target정보를 가지고  blackbox_exporter에게 요청  
 4)	설정되어있는 도메인정보를 가지고 target에게 http 요청
 5)	수집된 metric을 prometheus에 제공, prometheus는 받은 metric을 tsdb에 저장
 6)	tsdb에 저장된 metric을 prometheus api를 통해 select
 7)	metric을 AMS_E2E 형식에 맞게 재구성하여 json file로 생성
 8)	telegraf custom plugin을 통해 json file 파싱하여 influxdb 저장

2. 구성요소
    
2.1 prometheus

관제해야할 Target을 구성하여 blackbox_exporter를 통해 Metric을 polling
Metric을 자체 TSDB에 저장 (Default retention time 15D)

설치 위치
/usr/service/prometheus
                    
구성

/usr/service/prometheus 
[root@ams-prometheus-kt prometheus]# ls
LICENSE  NOTICE  console_libraries  consoles  data  prometheus  prometheus.log  prometheus.yml  promtool  tsdb
•	prometheus.yml : prometheus config file , Target 정보, 관제주기 등을 설정
•	prometheus.log : prometheus LOG. 
                    
실행 명령어 (init.d 등록)
•	실행
[root@ams-prometheus-kt /]# service prometheus start
prometheus start
•	 종료
[root@ams-prometheus-kt /]# service prometheus stop
prometheus stop
•	재실행
[root@ams-prometheus-kt /]# service prometheus restart
prometheus stop
prometheus start

2.2 blackbox_exporter
                      
Target의 도메인 정보를 구성 (header) 
Target의 Metric을 수집하여 prometheus에게 제공
                          
설치위치
/usr/service/blackbox
                               
구성
/usr/service/blackbox 
[root@ams-prometheus-kt blackbox]# ls
LICENSE  NOTICE  blackbox.log  blackbox.yml  blackbox_exporter
•	blackbox.yml : blackbox_exporter config file, Target에 정보를 구성 (HTTP Header, Cert Check skip)
•	blackbox.log : blackbox_exporter Log (retention time 7D)

명령어                               
•	실행
[root@ams-prometheus-kt /]# service blackbox start
blackbox start
•	종료
[root@ams-prometheus-kt /]# service blackbox stop
blackbox stop
•	재실행
[root@ams-prometheus-kt /]# service blackbox restart
blackbox stop

2.3 php
                          
Prometheus를 AMS에서 사용할수있도록 
•	Dynamic한 Target 구성
•	influxDB에 저장할 Metric file 생성 (json)
•	crontab에 등록하여 1분마다 실행
•	PHP 7.4.5 사용
•	gitlab : https://gitlab.solbox.com/ams/e2e-delivery-prometheus
                        
설치 위치
/usr/service/php
        
구성
[root@ams-prometheus-kt php]# ls
e2e_metric.ini  e2e_metric.log  e2e_metric.php  e2e_target.ini  e2e_target.log  e2e_target.php
e2e_target.php 
•	telco api를 polling하여 Target(E2E 모니터링 대상)을 구성한다.
•	prometheus.yml, blackbox.yml (config file)을 구성하여 배포 
•	설정을 반영 하기위해 prometheus와 blackbox_exporter를 reload
•	interval 60s (crontab) 
•	PECL yaml 사용 

e2e_metric.php
•	prometheus가 관측한 metric을 influxDB에 저장하기 위해 json형식의 file을 만든다.
•	prometheus api를 이용하여 prometheus에 저장된 메트릭값을 polling
•	metric을 E2E형식에 맞도록 변환
•	/tmp/prometheus에 일별로 디렉토리를 생성하여 1분마다 json file을 생성
•	생성한 json file을 /tmp/prometheus/e2e_delivery.json으로 overwrite
•	interval 60s (crontab) 
          
e2e_target.ini
•	e2e_target.php의 config file
•	e2e_target.php을 여러환경에서 사용할수 있도록 하기 위해 작성
e2e_target.ini 
[setting]
interval = 60 ;seconds
telco_api = ""  ;telco api
blackbox = "" ;blackbox ip:port

e2e_metric.ini
•	e2e_metric.php의 config file
•	e2e_metric.php을 여러환경에서 사용할수 있도록 하기 위해 작성
e2e_target.ini 
[setting]
prometheus = "" ;prometheus ip:port

e2e_target.log, e2e_metric.log
•	php 실행시 에러메시지가 발생하면 기록하기 위한 log file


2.4 e2e_delivery.json

Metric을 AMS E2E형식으로 influxDB에 저장하기 위한 json file
e2e_metric.php에 의해 생성됨
일별 디렉토리에 관측값 저장후 관측값을 e2e_delivery.json에 overwrite

형식
{
•	"svcname":"서비스명",
•	"svr_id":"FHS명",
•	"zone":"도메인주소",
•	"timeout":"timeout 시간"
•	"connect_time":"TCP 연결시간",
•	"http_code":"HTTP 상태코드",
•	"total_time":"총 수신시간",
•	"first_byte_time":"첫번째 바이트 수신시간",
•	"cert_expiry":"인증서 만료까지 남은 일수"
}

위치
/tmp/prometheus/

구성
[root@ams-prometheus-kt prometheus]# ls
20200612  20200613  20200614  20200615  20200616  20200617  20200618  20200619  e2e_delivery.json
/tmp/prometheus/20200612 
[root@ams-prometheus-kt 20200612]# ls
e2e_delivey.json_20200612000101 e2e_delivey.json_20200612060101 e2e_delivey.json_20200612120101 e2e_delivey.json_20200612180101
e2e_delivey.json_20200612000201 e2e_delivey.json_20200612060201 e2e_delivey.json_20200612120201 e2e_delivey.json_20200612180201
e2e_delivey.json_20200612000301 e2e_delivey.json_20200612060301 e2e_delivey.json_20200612120301 e2e_delivey.json_20200612180301
•	e2e_delivery.json : influxDB에 저장되는 Metric 원본 파일, 1분마다 새로 관측한 값으로 overwrite 
•	yyyymmdd : 일별 디렉토리 (retention time 7D)
•	yyyymmdd/e2e_delivey.json_yyyymmddmmss : 1분마다 관측한 metric data

2.5 TSDB data

Prometheus TSDB의 data file ( retention time 7D )

위치
/tmp/data/ ( default path : /data/ )

3. 서버구성하기

3.1 Prometheus 설치
•	wget https://github.com/prometheus/prometheus/releases/download/v2.18.1/prometheus-2.18.1.linux-amd64.tar.gz
•	tar -xvf prometheus-2.18.1.linux-amd64.tar.gz
•	mv prometheus-2.18.1.linux-amd64 /usr/service/prometheus
.
3.2 Blackbox_exporter 설치
•	wget https://github.com/prometheus/blackbox_exporter/releases/download/v0.16.0/blackbox_exporter-0.16.0.linux-amd64.tar.gz
•	tar -xvf blackbox_exporter-0.16.0.linux-amd64.tar.gz
•	mv blackbox_exporter-0.16.0.linux-amd64 /usr/service/blackbox

3.3 php 설치 (remi repository)

CentOS8
•	dnf install https://dl.fedoraproject.org/pub/epel/epel-release-latest-8.noarch.rpm
•	dnf install https://rpms.remirepo.net/enterprise/remi-release-8.rpm
•	dnf module enable php:remi-7.4
•	dnf install php php-cli php-common

CentOS7
•	yum install https://dl.fedoraproject.org/pub/epel/epel-release-latest-7.noarch.rpm
yum install http://rpms.remirepo.net/enterprise/remi-release-7.rpm
•	yum install yum-utils
•	yum-config-manager --enable remi-php74
•	yum install php php-cli php-common

3.4 PECL yaml 설치 (repo power tools)
•	yum install php-pear
•	yum install php-devel
•	yum install libyaml-devel
•	pecl install yaml
•	echo "extension=yaml.so" > /etc/php.d/yaml.ini

3.5 killall 설치 (prometheus reload시 필요)
•	yum install psmisc

3.6 php 소스 다운로드
•	php 소스 다운로드
•	mv /usr/service/php

3.7 openfile limit 설정
/etc/security/limits.conf 
root hard nofile 1000000
root soft nofile 1000000
•	ulimit -a → open files가 1000000이 되었는지 확인
4.7 init.d 등록
/etc/init.d/prometheus 
case "$1" in

 start)
  nohup /usr/service/prometheus/prometheus --config.file="/usr/service/prometheus/prometheus.yml" --storage.tsdb.path=/tmp/data/. --storage.tsdb.retention.time=7d --log.level=info >> /usr/service/prometheus/prometheus.log 2>&1 &
  echo "prometheus start"
  exit 1
 ;;

 stop)
  A=$(pidof prometheus)
  kill -9 $A
  echo "prometheus stop"
  exit 1
 ;;

 restart)
  service prometheus stop
  service prometheus start
 ;;

esac
/etc/init.d/blackbox 
case "$1" in

 start)
  nohup /usr/service/blackbox/blackbox_exporter --config.file=/usr/service/blackbox/blackbox.yml --log.level=info >> /usr/service/blackbox/blackbox.log 2>&1 &
  echo "blackbox start"
  exit 1
 ;;

 stop)
  A=$(pidof blackbox_exporter)
  kill -9 $A
  echo "blackbox stop"
  exit 1
 ;;

 restart)
  service blackbox stop
  service blackbox start
 ;;

esac
.
3.8 crontab 등록 (json 파일 보관주기, php 실행)
/etc/crontab 
0 0 * * * find /tmp/prometheus/* -mtime +6 -exec rm -rf {} \;
* * * * * root php /usr/service/php/e2e_target.php >> /usr/service/php/e2e_target.log
* * * * * root php /usr/service/php/e2e_metric.php >> /usr/service/php/e2e_metric.log

3.9 showall 등록
/usr/service/bin/showall 
#!/bin/sh

proc_check()
{
    #CHECK=`ps waxo stat,start,time,ruid,rss,size,tty,tpgid,sess,pgrp,ppid,pid,pcpu,comm|grep $1|grep -v grep|grep -v tail|grep -v vi`
    CHECK=`ps waxo stat,start,time,ruid,rss,size,tty,tpgid,sess,pgrp,ppid,pid,pcpu,args|grep $1|grep -v grep|grep -v tail|grep -v "vi "|grep -v "vim "`
    if [ "$CHECK" ]
    then
        echo "$CHECK"
        echo "--------------------------------------------------------------------------"
    else
        echo "##          $1 IS NOT RUNNING !"
        echo "--------------------------------------------------------------------------"
    fi
}
echo "--------------------------------------------------------------------------------------------"
echo "STAT    START     TIME  RUID   RSS  SIZE TT       TPGID  SESS  PGRP  PPID   PID %CPU COMMAND"
echo "--------------------------------------------------------------------------------------------"
proc_check prometheus
proc_check blackbox
proc_check telegraf
echo "--------------------------------------------------------------------------------------------"
•	chmod 0755 /usr/service/bin/showall
•	alias 등록 
1.	vi ~/.bashrc
2.	alias showall='/usr/service/bin/showall' 추가후 저장
3.	source ~/.bashrc

3.10 motd 등록
/etc/motd 
-- E2E start --
service prometheus start
service blackbox start

-- E2E stop --
service prometheus stop
service blackbox stop

-- E2E restart --
service prometheus restart
service blackbox restart
