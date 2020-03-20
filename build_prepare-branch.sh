#!/bin/sh

# Prepares the branch before building:
# Only run octopus merges to include all feature branches on */docker-deploy branches

# Expects a branch nama as argument
# DOCKER_ENV_CI_COMMIT_BRANCH=o-master/docker-deploy

f_fatal() {
    echo "$@"
    exit 1
}
docker_deploy="docker-deploy"

branch="$1"
[ -n "$branch" ] || f_fatal "Arg. missing"

echo "Processing: '$branch'"
if echo "$branch" | grep -q ".*/$docker_deploy"
then
    # Classic 
    branch_root=$(echo "$branch" | sed '!/.*$!!')
    echo "Perform Octopus merge for '$branch_root'"
    git branch --list -r "origin/$branch_root/feat/*"
    git branch --list -r "origin/$branch_root/feat/*" | \
		xargs git merge --verbose --no-commit
else
    echo "No Octopus merge required for '$branch_root'"
fi

