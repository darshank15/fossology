FROM postgres:9.6
RUN apt update && apt install curl bsdmainutils  python3 vim -y
#ADD report-uuid.sh /report-uuid.sh
#RUN chmod 0744 /report-uuid.sh

ADD fossology_kpi_collect.sh /fossology_kpi_collect.sh 
ADD report-kpi.sh /report-kpi.sh
RUN chmod 0744 /report-kpi.sh

ADD report-uuid-cron /etc/cron.d/report-uuid-cron
RUN chmod 644 /etc/cron.d/report-uuid-cron

RUN touch /var/log/cron.log

RUN crontab /etc/cron.d/report-uuid-cron

CMD export|grep FOSS>/.env && cron && tailf /var/log/cron.log

