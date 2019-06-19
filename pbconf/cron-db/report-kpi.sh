#ebin/bash
source /.env
export PGPASSWORD=$FOSSOLOGY_DB_PASSWORD
REPORT_LOCATION=/tmp

rm $REPORT_LOCATION/*.csv

/fossology_kpi_collect.sh -d $REPORT_LOCATION

function convert {
FILE_TO_CONVERT="$1" python3 - <<END
import csv
import json
import os

keys = []
content = {}
with open(os.environ['FILE_TO_CONVERT'], newline='') as csvfile:    
    spamreader = csv.reader(csvfile, delimiter=';', quotechar=' ')
    for line,data in enumerate(spamreader): 
        if(line==0): keys = data
        else: 
            for k,key in enumerate(keys): content[key]=data[k]

print(json.dumps(content,separators=(',', ':')))
END
}

JSON=$(convert $REPORT_LOCATION/kpi_v01.csv)

curl --header "Content-Type: application/json" --request POST  --data $JSON http://api:5000 
