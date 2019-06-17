#!/bin/bash
source /.env
export PGPASSWORD=$FOSSOLOGY_DB_PASSWORD

KPI_VERSION=01
KPI_DATE_TAG=$(date +%Y-%m-%d_%H-%M)
KPI_CSV_SEP=';'

_usage()
{
	[ -n "$1" ] && echo "\nError: $1"
cat <<EOS

Usage: $(basename $0)  -d <dest_directory> | -r | -h
Options:
    -d destination directory for data and logs
    -r Dry run : do not write anything to file
    -h this help

EOS
	[ -n "$1" ] && exit 1
	exit
}

while getopts "hd:r" opt; do
    case $opt in
    r) dry_run=true
       ;;
    d)  kpi_dir=$OPTARG ;;
    h)  _usage ;;
    \?) _usage "Wrong options" ;;
  esac
done

if [ "$dry_run" = "true" ]
then
   kpi_file=/dev/null
   kpi_logs=/dev/null
else
	[ -n "$kpi_dir" ] || _usage
	[ -d "$kpi_dir" ] || _usage "Cannot find directory '$kpi_dir'"
	kpi_file=$kpi_dir/kpi_v${KPI_VERSION}.csv
	kpi_logs=$kpi_dir/kpi.log
fi

f_log() {
	echo "$(date) $*" | tee -a $kpi_logs
}

f_fatal() {
	f_log "ERROR: $*"
	exit 1
}

#f_db_query() { docker exec fossology_db_1 psql -h localhost fossology fossy -c "$1"; }
f_db_query() {
	psql -h $FOSSOLOGY_DB_HOST -U $FOSSOLOGY_DB_USER -d $FOSSOLOGY_DB_NAME -A -t -c "$1";
}
f_db_query_int() { f_db_query "$1" | sed -n '/\s*[0-9][0-9]*$/s/^ *//p'; }

###################
# Define KPIs
KPI_COUNT=6
kpi_01_name="user_count"
kpi_02_name="job_count"
kpi_03_name="reportgen_count"
kpi_04_name="pfile_count"
kpi_05_name="avg_pfile_size"
kpi_06_name="upload_count"
kpi_01_query() { f_db_query_int "select count(*) from users  where user_pk > 3;"; }
kpi_02_query() { f_db_query_int "select count(*) from job;"; }
kpi_03_query() { f_db_query_int "select count(*) from reportgen;"; }
kpi_04_query() { f_db_query_int "select count(*) from pfile;"; }
kpi_05_query() { f_db_query_int "select round(avg(pfile_size)) from pfile;"; }
kpi_06_query() { f_db_query_int "select count(*) from upload where pfile_fk>0;"; }

##################
# Write CSV header
f_get_header() {
	echo -n "date"
	for i in $(seq 1 $KPI_COUNT)
	do
		[ $i -le 9 ] && i=0$i
		echo -n ';'
		eval echo -n "\$kpi_${i}_name"
	done
}

##################
# Write KPI values
f_get_kpis() {
	echo -n "$KPI_DATE_TAG"
	for i in $(seq 1 $KPI_COUNT)
	do
		[ $i -le 9 ] && i=0$i
		echo -n ';'
		echo -n $(eval "kpi_${i}_query")
	done
}


##################

f_log "Start: $kpi_file / $KPI_DATE_TAG"
header=$(f_get_header )
data=$(f_get_kpis || f_fatal 'Failed executing DB queries')


if [ "$dry_run" != "true" ]
then
	touch $kpi_file || f_fatal "Can't write to file '$kpi_file'"
	test -s $kpi_file || echo "$header" > $kpi_file
	echo "$data" >> $kpi_file
fi

cat <<EOS

script: $(basename $0)
Date tag: $KPI_DATE_TAG
KPI Version: $KPI_VERSION
Output file: $kpi_file

EOS
echo "****"
(echo "$header"; echo "$data") | column -t -n -s "$KPI_CSV_SEP"
echo "****"
echo

f_log "End  : $kpi_file / $KPI_DATE_TAG"

