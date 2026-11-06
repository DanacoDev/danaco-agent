---
slug: installation
nav: Installation
title: Installation
---

Agent Danaco installs on Linux from an interactive terminal. Ubuntu is recommended. Windows is not supported. Docker is not required. The project is open source under the MIT license.

## Before you start

You need a terminal on the machine that will run the agent. The installer exits if it is not attached to a terminal, because it asks questions and waits for answers.

Have a Web UI password ready. It must be at least 8 characters, and you will type it twice. The installer adds Ollama when it is missing.

## Get the files

Clone the source from GitHub and run the installer from that folder. That path is free for anyone who is comfortable in a terminal.

```
git clone {{github_url}} danaco-agent
cd danaco-agent
bash install.sh
```

If you prefer a guided package for less technical users or a business rollout, buy the installer from the Danaco website and run the same `bash install.sh` step from the folder it creates. Under the hood it sets up this same MIT-licensed project.

The script installs PHP if it is missing, shows your hardware, and then asks the questions below. At the end it opens the Web UI in your browser.

## If Agent Danaco is already installed

When data/app.sqlite is already in the folder, the script stops and offers three choices.

- Replace the old files. It fetches the current source and runs the installer again.
- Use the existing files and continue.
- Create a new numbered folder next to this one and install a fresh copy there.

## PHP

Agent Danaco needs PHP 8.2 or newer, with pdo_sqlite, curl, openssl, mbstring, dom, and zip, plus the sqlite3 command. If those are missing, the installer tries to add them.

- Debian or Ubuntu: php-cli, php-sqlite3, sqlite3, php-curl, php-mbstring, php-xml, and php-zip. apt may ask for your password.

On another Linux system the script prints the package list and stops, so you can install them yourself and run it again.

## Hardware

The script prints your NVIDIA GPU name and memory when nvidia-smi is available. Other Linux machines without an NVIDIA GPU get a short note that none was detected.

## Specifications

On install, Agent Danaco suggests a local model from discrete GPU memory and system RAM. Unified memory does not count as GPU memory, so a Mac with no separate graphics card is treated as having none. A Linux machine with no NVIDIA GPU is treated the same way.

- With 16 GB or more of discrete GPU memory, the suggested model is `qwen3.6:35b-a3b-nvfp4` when the NVIDIA GPU has compute capability 10.0 or newer, and `qwen3.6:35b-a3b-q4_K_M` otherwise.
- With less than 16 GB of discrete GPU memory and 8 GB of RAM or more, the suggested model is `qwen3.5:9b-q4_K_M`.
- With less memory than that, including less than 3 GB of GPU memory and only 4 GB of RAM, the suggested choice is the CPU failsafe, `qwen2.5:0.5b`.

Ollama is installed when it is missing. A model that is already installed is reused.

![How the installer picks a local model](model-choice.svg)

The 0.5B model is always installed. The larger model is downloaded only when you pick a local model.

## Choose a model

The hardware suggestion is choice 1. Pressing Enter keeps it.

When that suggestion is a local model, the list also shows models Ollama already has, External LLM, and CPU failsafe. The suggested tag is not listed twice.

When the suggestion is the CPU failsafe, that is choice 1. External LLM and a local model stay available.

External LLM does not download a larger model. CPU failsafe does not either. Choosing a local model downloads that model when it is not already present.

## Network and port

The script asks whether other devices on the same LAN should reach the agent. The default is no, which binds to 127.0.0.1. Answering yes binds to 0.0.0.0.

On Linux, a yes also opens SSH and the chosen port in ufw when ufw is installed. If ufw is missing, you open those ports yourself.

The Web UI uses port 8000 when it is free. If 8000 is taken by something else, the script walks forward and uses the next free port through 8099. An existing Agent Danaco on 8000 is reused.

## Web UI password

You type the password twice. It is hidden while you type. Both entries must match, and the password must be at least 8 characters. The script will not continue without one.

## Ollama and the failsafe model

The installer downloads and installs Ollama when it is missing, for every choice, then starts it if it is not already answering on 127.0.0.1 port 11434. It always downloads qwen2.5:0.5b, the CPU failsafe, unless that model is already present. The download stays on screen, and the installer waits until it finishes.

A larger local model is downloaded only when you pick one. External LLM does not download it. If Ollama cannot be installed, or a download fails, the browser is not opened. The script points you to https://ollama.com.

## Local certificate

The installer creates a local certificate so the address is https. Debian-style Linux copies it into /usr/local/share/ca-certificates and updates the CA store. If that trust step is skipped, the browser asks before the first visit.

## Open it again

The browser opens https://127.0.0.1 and the port the installer chose. If you allowed LAN access, the script also prints the https addresses other devices on the network can use.

Later, the `danaco` command opens the same UI. It also backs up projects, restores a backup, updates from GitHub, and uninstalls.

```
danaco
danaco backup
danaco restore
danaco update
danaco uninstall
```
