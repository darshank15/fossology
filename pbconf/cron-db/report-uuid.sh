#/bin/bash
source /.env
export PGPASSWORD=$FOSSOLOGY_DB_PASSWORD
JSON=`psql -h $FOSSOLOGY_DB_HOST -U $FOSSOLOGY_DB_USER -d $FOSSOLOGY_DB_NAME -A -t \
	-c "SELECT row_to_json(row) FROM (SELECT instance_uuid FROM instance) row;"`
#psql -h $FOSSOLOGY_DB_HOST -U $FOSSOLOGY_DB_USER -d $FOSSOLOGY_DB_NAME -A -t << EOF
#	SELECT row_to_json(row) FROM (SELECT instance_uuid FROM instance) row;
#EOF

curl -header "Content-Type: application-json" --request POST --data $JSON http://api:5000
