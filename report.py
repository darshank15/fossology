#!/usr/bin/env python
import os
import time

from socket import socket, AF_INET, SOCK_STREAM, SHUT_WR
import psycopg2



# Fossology DB configuration file
DB_CONFIG_FILE = "/usr/local/etc/fossology/Db.conf"

# GRAPHITE SETTINGS
GRAPHITE_HOST = os.environ.get("GRAPTHITE_HOST", "graphite")
GRAPHITE_PORT = os.environ.get("GRAPHITE_PORT", 2004)
# true only if envvar==True|1
PICKLE_SEND = bool(os.environ.get("PICKLE_SEND", "True") in ["True", "1"])

CONFIG = {}
# parse DB_CONFIG_FILE
with open(DB_CONFIG_FILE, mode="r") as dbf:
    config_entry = dbf.readline()
    while config_entry:
        config_entry = config_entry.split("=")
        CONFIG[config_entry[0]] = config_entry[1].strip().replace(";", "")

        config_entry = dbf.readline()

# produces "conf1=val1 conf2=val2 conf3=val3 ..."
config = " ".join(["=".join(config) for config in CONFIG.items()])


def _query(connection, query, single=False):
    cur = connection.cursor()
    cur.execute(query)
    result = cur.fetchone() if single else cur.fetchall()
    return result


def report(connection):
    _result = {}    
    for query in [
            "number_of_users", "number_of_groups", "number_of_file_uploads",
            "number_of_projects__theoretically", "number_of_url_uploads",
            "agents_count"]:
        result = _query(connection, QUERIES[query])
        _result[query] = result if len(result) > 1 else result[0]
    return _result


QUERIES = {
    'uuid': "SELECT instance_uuid uuid FROM instance;",
    'number_of_users': "SELECT count(u.*) AS users FROM users u;",
    'number_of_groups': "SELECT count(g.*) AS groups FROM groups g;",
    'number_of_projects__theoretically': "SELECT count(up.*) as uploads from (select distinct upload_mode, upload_origin from upload) up;",
    'number_of_file_uploads': "SELECT count(up1.upload_origin) as file_uploads FROM upload up1 WHERE up1.upload_mode = 104;",
    'number_of_url_uploads': "SELECT count(up2.upload_origin) as url_uploads FROM upload up2 WHERE up2.upload_mode = 100;",
    'agents_count': "SELECT ag.agent_name,count(jq.*) AS fired_jobs FROM agent ag LEFT OUTER JOIN jobqueue jq ON (jq.jq_type = ag.agent_name) GROUP BY ag.agent_name ORDER BY fired_jobs DESC;"
    }


def prepare_report(data, prefix=None):

    graphite_metric = []
    root = prefix

    def dig(r, data):
        if isinstance(data, list):
            multi = []
            for d in data:
                multi.append(dig(r, d))
            return multi

        tuple_length = len(data)

        mask_length = tuple_length - 1  # if tuple_length > 1 else 0
        mask = ".".join(["%s" for _ in range(mask_length)])

        if len(mask) > 0:
            mask = "{}.{}".format(r, mask)
        else:
            mask = "{}".format(r)
        mask += " %s"  # finaly mask is '%s.%s.%s %s'
        return mask % data

    for metric, v in data.items():
        digged = dig("{}.{}".format(root, metric), v)
        if isinstance(digged, list):
            for metric in digged:
                graphite_metric.append(metric)
        else:
            graphite_metric.append(digged)

    return graphite_metric


def send(host, port, message):
    """Sends messge through soncet """
    sock = socket(AF_INET, SOCK_STREAM)
    sock.connect((host, port))
    sock.sendall(message)
    time.sleep(1)
    sock.shutdown(SHUT_WR)

    res = ""
    while True:
        data = sock.recv(1024)
        if not data:
            break
        res += data.decode()

    print res
    sock.close()


if __name__ == "__main__":
    connection = None
    try:
        connection = psycopg2.connect(config)
        uuid = _query(connection, QUERIES['uuid'], single=True)[0]  # tuple
        raw_report = report(connection)
        prefix = "fossology.%s" % uuid
        results = prepare_report(raw_report, prefix=prefix)
    except (Exception, psycopg2.DatabaseError) as error:
        print error
    finally:
        if connection:
            connection.close()
    for metric in results:
        print metric.split(" ")

    if PICKLE_SEND:
        import pickle
        import struct
        timestamp = time.time()
        pre_tuples = [x.split(" ") for x in results]
        tpls = [tuple([t[0], (time.time(), t[1])]) for t in pre_tuples]
        payload = pickle.dumps(tpls, protocol=2)
        header = struct.pack("!L", len(payload))
        message = header + payload

        send(GRAPHITE_HOST, GRAPHITE_PORT, message)

# example usage:
# python report.py |xargs -I % sh -c "echo % >>out"
