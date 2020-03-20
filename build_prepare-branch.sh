#!/bin/sh

# Prepares the branch before building:
# Only run octopus merges to include all feature branches on */docker-deploy branches

# Expects a branch nama as argument
# DOCKER_ENV_CI_COMMIT_BRANCH=o-master/docker-deploy

f_fatal() {
    echo "$@"
    exit 1
}

f_log() {
    echo "<<< $*"
}

docker_deploy="docker-deploy"

branch="$1"
[ -n "$branch" ] || f_fatal "Arg. missing"

f_log "-----------------------------------------------"
f_log "$(basename $0)"
f_log  "Processing: '$branch'"
if echo "$branch" | grep -q ".*/$docker_deploy"
then
    # Classic 
    branch_root=$(echo "$branch" | sed 's!/.*$!!')
    f_log "Perform Octopus merge for '$branch_root'"
    git branch --list -r "origin/$branch_root/feat/*"
    git branch --list -r "origin/$branch_root/feat/*" | \
		xargs git merge --verbose --no-commit
else
    f_log "No Octopus merge required for '$branch_root'"
fi
f_log "-----------------------------------------------"
