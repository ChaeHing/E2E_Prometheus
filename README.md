# E2E_Prometheus

1. Prometheus를 통해 모니터링타겟에 http 응답을 확인 - blackbox exporter 사용
2. php로 input과 output을 control
3. input : e2e_target.php를 통해 모니터링타겟의 정보를 가져와 preometheus 설정을 구성한뒤 reload
4. output : e2e_metric.php를 통해 관제한 metric정보를 telegraf custom plugin 형식에 맞게 정제하여 파일생성
