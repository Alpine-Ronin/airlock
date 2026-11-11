#!/bin/sh
# GitHub's Ubuntu runners fetch packages from Azure's mirror only; when its name stops resolving, every apt-get
# fails, so fall back to Ubuntu's own archive.
set -eu

if ! getent hosts azure.archive.ubuntu.com > /dev/null; then
    echo 'azure.archive.ubuntu.com does not resolve: using archive.ubuntu.com'
    echo 'http://archive.ubuntu.com/ubuntu/' | sudo tee /etc/apt/apt-mirrors.txt > /dev/null
fi

sudo apt-get -o Acquire::Retries=3 update
sudo apt-get -o Acquire::Retries=3 install -y bubblewrap
# Ubuntu 24.04's AppArmor forbids unprivileged user namespaces, which bubblewrap needs.
sudo sysctl -w kernel.apparmor_restrict_unprivileged_userns=0
